<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\Checkout\Payment\PaymentException;
use Psr\Log\LoggerInterface;

class PaymentStateService
{
    protected TranslatorInterface $translator;
    protected OrderTransactionStateHandler $transactionStateHandler;
    protected StateMachineRegistry $stateMachineRegistry;
    protected AccountService $accountService;
    protected LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        TranslatorInterface $translator,
        AccountService $accountService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->translator = $translator;
        $this->accountService = $accountService;
        $this->logger = $logger;
    }

    public function finalizePayment(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->loginCustomer($salesChannelContext->getCustomerId(), $salesChannelContext);
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        try {
            $this->handlePaymentFinalization($transaction, $request, $transactionId, $context);
        } catch (PaymentException $e) {
            $this->logger->error('Payment finalization failed', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage(),
                'statusCode' => $this->getPaymentStatusCode($request),
                'paymentMethod' => $request->get('brq_payment_method')
            ]);
            throw $e;
        }
    }

    private function handlePaymentFinalization(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        string $transactionId,
        Context $context
    ): void {
        if ($this->shouldCancelPayment($request)) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                $this->translator->trans('buckaroo.userCanceled')
            );
        }

        $availableTransitions = $this->getAvailableTransitions($transactionId, $context);
        $this->processPaymentState($request, $availableTransitions, $transactionId, $context);
    }

    private function shouldCancelPayment(Request $request): bool
    {
        return $request->query->getBoolean('cancel') ||
            $this->isGroupTransactionCancel($request) ||
            $this->isPayPalPending($request) ||
            $this->isCanceledPaymentRequest($request);
    }

    private function processPaymentState(
        Request $request,
        array $availableTransitions,
        string $transactionId,
        Context $context
    ): void {
        if ($this->isPendingPaymentRequest($request) &&
            $this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_DO_PAY)) {
            $this->transactionStateHandler->process($transactionId, $context);
            return;
        }

        if ($this->isFailedPaymentRequest($request)) {
            $this->handleFailedPayment($request, $availableTransitions, $transactionId);
        }
    }

    private function handleFailedPayment(
        Request $request,
        array $availableTransitions,
        string $transactionId
    ): void {
        $errorMessage = $this->getStatusMessageByStatusCode($request);
        
        if ($this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_FAIL)) {
            throw PaymentException::asyncProcessInterrupted($transactionId, $errorMessage);
        }

        if ($this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_CANCEL)) {
            throw PaymentException::asyncProcessInterrupted($transactionId, $errorMessage);
        }
    }

    private function loginCustomer(?string $customerId, SalesChannelContext $salesChannelContext): void
    {
        if ($customerId === null) {
            return;
        }

        try {
            $this->accountService->loginById($customerId, $salesChannelContext);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to login customer', [
                'customerId' => $customerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if its a canceled group transaction
     *
     * @param Request $request
     *
     * @return boolean
     */
    private function isGroupTransactionCancel(Request $request): bool
    {
        return $request->query->get('scenario') === 'Cancellation';
    }

    /**
     * @param array<mixed> $availableTransitions
     * @param string $transition
     *
     * @return boolean
     */
    private function canTransition(array $availableTransitions, string $transition): bool
    {
        return in_array($transition, $availableTransitions);
    }

    /**
     * @param string $transactionId
     * @param Context $context
     *
     * @return array<mixed>
     */
    private function getAvailableTransitions(string $transactionId, Context $context): array
    {
        try {
            $availableTransitions = $this->stateMachineRegistry->getAvailableTransitions(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                'stateId',
                $context
            );

            return array_map(function (StateMachineTransitionEntity $transition) {
                return $transition->getActionName();
            }, $availableTransitions);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get available transitions', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function isPayPalPending(Request $request): bool
    {
        return $request->get('brq_payment_method') === 'paypal' &&
            $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING;
    }

    private function isPendingPaymentRequest(Request $request): bool
    {
        return $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING;
    }

    private function isFailedPaymentRequest(Request $request): bool
    {
        $statusCode = $request->get('brq_statuscode');
        if (!is_string($statusCode)) {
            return false;
        }

        return in_array($statusCode, [
            ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
            ResponseStatus::BUCKAROO_STATUSCODE_REJECTED,
            ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE
        ]);
    }

    private function isCanceledPaymentRequest(Request $request): bool
    {
        return $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
    }

    private function getPaymentStatusCode(Request $request): ?string
    {
        $statusCode = $request->get('brq_statuscode');
        return is_string($statusCode) ? $statusCode : null;
    }

    /**
     * @param Request $request
     * @return string
     */
    private function getStatusMessageByStatusCode(Request $request): string
    {
        $statusCode = $this->getPaymentStatusCode($request);
        if ($statusCode === null) {
            return '';
        }

        if ($this->isBillinkRejected($request, $statusCode)) {
            return $this->translator->trans('buckaroo.billinkRejectedMessage');
        }

        return $this->getErrorMessageByStatusCode($statusCode);
    }

    private function isBillinkRejected(Request $request, string $statusCode): bool
    {
        return $request->get('brq_payment_method') === 'Billink' &&
            $statusCode === ResponseStatus::BUCKAROO_STATUSCODE_REJECTED;
    }

    private function getErrorMessageByStatusCode(string $statusCode): string
    {
        $errorMessages = [
            ResponseStatus::BUCKAROO_STATUSCODE_FAILED => 'buckaroo.statuscode_failed',
            ResponseStatus::BUCKAROO_STATUSCODE_REJECTED => 'buckaroo.statuscode_failed'
        ];

        return isset($errorMessages[$statusCode])
            ? $this->translator->trans($errorMessages[$statusCode])
            : '';
    }
}
