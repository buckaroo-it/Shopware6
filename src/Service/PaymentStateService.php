<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Buckaroo\Shopware6\Service\Exceptions\PaymentFailedException;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;

class PaymentStateService
{
    protected TranslatorInterface $translator;

    protected OrderTransactionStateHandler $transactionStateHandler;

    protected StateMachineRegistry $stateMachineRegistry;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        TranslatorInterface $translator
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->translator = $translator;
    }

    public function finalizePayment(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {

        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        if (
            $request->query->getBoolean('cancel') ||
            $this->isGroupTransactionCancel($request) ||
            $this->isPayPalPending($request) ||
            $this->isCanceledPaymentRequest($request)
        ) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                $this->translator->trans('buckaroo.userCanceled')
            );
        }

        $availableTransitions = $this->getAvailableTransitions(
            $transactionId,
            $context
        );

        if (
            $this->isPendingPaymentRequest($request) &&
            $this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_DO_PAY)
        ) {
            $this->transactionStateHandler->process($transactionId, $context);
        }

        if (
            $this->isFailedPaymentRequest($request) &&
            $this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_FAIL)
        ) {
            throw new PaymentFailedException(
                $transactionId,
                $this->getStatusMessageByStatusCode($request),
                [],
                null,
                $this->getPaymentStatusCode($request)
            );
        }
    }

    /**
     * Check if its a canceled group transaction
     *
     * @param Request $request
     *
     * @return boolean
     */
    private function isGroupTransactionCancel(Request $request): bool {
        return $request->query->getString('scenario') === 'Cancellation';
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
        $availableTransitions = $this->stateMachineRegistry->getAvailableTransitions(
            OrderTransactionDefinition::ENTITY_NAME,
            $transactionId,
            'stateId',
            $context
        );

        return array_map(function (StateMachineTransitionEntity $transition) {
            return $transition->getActionName();
        }, $availableTransitions);
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
        return
            is_string($request->get('brq_statuscode')) &&
            in_array(
                $request->get('brq_statuscode'),
                [
                    ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                    ResponseStatus::BUCKAROO_STATUSCODE_REJECTED,
                    ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE
                ]
            );
    }

    private function isCanceledPaymentRequest(Request $request): bool
    {
        return $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
    }

    private function getPaymentStatusCode(Request $request): ?string
    {
        $statusCode = $request->get('brq_statuscode');
        if (is_string($statusCode)) {
            return $statusCode;
        }
        return null;
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

        if (
            $request->get('brq_payment_method') === 'Billink' &&
            $statusCode === ResponseStatus::BUCKAROO_STATUSCODE_REJECTED
        ) {
            return $this->translator->trans('buckaroo.billinkRejectedMessage');
        }

        $statusCodeAddErrorMessage = [];
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_FAILED] =
            $this->translator->trans('buckaroo.statuscode_failed');
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_REJECTED] =
            $this->translator->trans('buckaroo.statuscode_failed');

        return isset($statusCodeAddErrorMessage[$statusCode]) ? $statusCodeAddErrorMessage[$statusCode] : '';
    }
}
