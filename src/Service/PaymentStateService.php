<?php

declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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

    protected  OrderTransactionStateHandler $transactionStateHandler;

    protected StateMachineRegistry $stateMachineRegistry;

    protected CsrfTokenManagerInterface $csrfTokenManager;
    
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        CsrfTokenManagerInterface $csrfTokenManager,
        TranslatorInterface $translator
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->translator = $translator;
    }

    public function finalizePayment(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    )
    {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        if (
            $request->query->getBoolean('cancel') ||
            $this->isPayPalPending($request) ||
            $this->isCanceledPaymentRequest($request)
        ) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                $this->translator->trans('buckaroo.messages.userCanceled')
            );
        }

        $availableTransitions = $this->getAvailableTransitions(
            $transactionId,
            $context
        );

        if(
            $this->isPendingPaymentRequest($request) &&
            $this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_DO_PAY)
        ) {
            $this->transactionStateHandler->process($transactionId, $context);
            $availableTransitions = $this->getAvailableTransitions(
                $transactionId,
                $context
            );
        }
      
        if(
            $this->isFailedPaymentRequest($request) &&
            $this->canTransition($availableTransitions, StateMachineTransitionActions::ACTION_FAIL)
        ) {
            throw new PaymentFailedException(
                $transactionId,
                $this->getStatusMessageByStatusCode(
                    $request->get('brq_statuscode')
                )
            );
        }

    }

    public function getPaymentCsrf()
    {
        return $this->csrfTokenManager->getToken('payment.finalize.transaction')->getValue();
    }
    
    private function canTransition($availableTransitions, $transition) {
        return in_array($transition, $availableTransitions);
    }

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

    private function isPayPalPending(Request $request) {
        return $request->get('brq_payment_method') === 'paypal' && 
        $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING;
    }

    private function isPendingPaymentRequest(Request $request) {
        return $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING;
    }
    
    private function isFailedPaymentRequest(Request $request) {
        return in_array(
            (string)$request->get('brq_statuscode'),
            [
                ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                ResponseStatus::BUCKAROO_STATUSCODE_REJECTED
            ]
        );
    }

    private function isCanceledPaymentRequest(Request $request) {
        return $request->get('brq_statuscode') == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
    }

    private function getStatusMessageByStatusCode($statusCode)
    {
        $statusCodeAddErrorMessage = [];
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_FAILED] = 
        $this->translator->trans('buckaroo.messages.statuscode_failed');
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_REJECTED] =
            $this->translator->trans('buckaroo.messages.statuscode_failed');

        return isset($statusCodeAddErrorMessage[$statusCode]) ? $statusCodeAddErrorMessage[$statusCode] : '';
    }

}