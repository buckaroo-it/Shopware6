<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Entity\OrderData\OrderDataRepository;
use Buckaroo\Shopware6\Service\LockService;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Payment\PaymentException;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

class OrderFinalizeService
{
    protected LockService $lockService;

    protected AccountService $accountService;

    protected ChangeStateService $changeStateService;

    protected OrderDataRepository $orderDataRepository;

    public function __construct(
        OrderDataRepository $orderDataRepository,
        ChangeStateService $changeStateService,
        AccountService $accountService,
        LockService $lockService
    ) {
        $this->accountService = $accountService;
        $this->lockService = $lockService;
        $this->changeStateService = $changeStateService;
        $this->orderDataRepository = $orderDataRepository;
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->loginCustomer($salesChannelContext->getCustomerId(), $salesChannelContext);
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();
        $state = null;
        $stateCode = null;

        if (
            $request->query->getBoolean('cancel') ||
            $this->isGroupTransactionCancel($request) ||
            $this->isPayPalPending($request) ||
            $this->isCanceledPaymentRequest($request)
        ) {
            $state =  OrderTransactionStates::STATE_CANCELLED;
            $stateCode = PaymentException::PAYMENT_CUSTOMER_CANCELED_EXTERNAL;
        }

        if (
            $this->isPendingPaymentRequest($request)
        ) {
            $state = OrderTransactionStates::STATE_IN_PROGRESS;
        }

        if (
            $this->isFailedPaymentRequest($request)
        ) {
            $state = OrderTransactionStates::STATE_FAILED;
            $stateCode = "PAYMENT_FAILED_ERROR_" . $this->getPaymentStatusCode($request);
        }

        $this->setOrderReturnStatus($orderTransactionId, $stateCode, $context);
        $this->changeState($orderTransactionId, $state, $context);
    }

    private function setOrderReturnStatus(
        string $orderTransactionId,
        ?string $stateCode,
        Context $context
    ): void {
        if ($stateCode === null) {
            return;
        }
        $this->orderDataRepository->setOrderReturnStatus(
            $orderTransactionId,
            $stateCode,
            $context
        );
    }

    private function changeState(
        string $orderTransactionId,
        ?string $state,
        Context $context
    ): void {
        if ($state === null) {
            return;
        }
        $lock = $this->lockService->getLock($orderTransactionId);
        if ($lock->acquire()) {
            $this->changeStateService->setState(
                $orderTransactionId,
                $state,
                $context
            );
            $lock->release();
        }
    }

    private function loginCustomer(?string $customerId, SalesChannelContext $salesChannelContext): void
    {
        if ($customerId === null) {
            return;
        }

        $this->accountService->loginById($customerId, $salesChannelContext);
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
}
