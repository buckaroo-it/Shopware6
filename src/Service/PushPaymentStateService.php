<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\SavedTransactionState;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

class PushPaymentStateService
{
    private EntityRepository $transactionOrderRepository;

    public function __construct(EntityRepository $transactionOrderRepository) {
        $this->transactionOrderRepository = $transactionOrderRepository;
    }

    /**
     * Get order state
     *
     * @param EngineResponseCollection $engineResponses
     * @param Context $context
     * @param string $orderTransactionId
     *
     * @return string
     */
    public function getState(
        EngineResponseCollection $engineResponses,
        Context $context,
        string $orderTransactionId
    ): string {
        $state = new SavedTransactionState($engineResponses);
        $orderTransaction = $this->getOrderTransaction($orderTransactionId, $context);
        if ($orderTransaction === null) {
            return OrderTransactionStates::STATE_IN_PROGRESS;
        }


        if ($state->hasRefunds()) {
            return $this->getRefundStatus($state, $orderTransaction);
        }

        if ($state->hasPayments()) {
            return $this->getPayStatus($state, $orderTransaction);
        }

        if ($state->hasAuthorization()) {
            return OrderTransactionStates::STATE_AUTHORIZED;
        }

        if ($state->hasCancellations()) { 
            return OrderTransactionStates::STATE_CANCELLED;
        }

        return OrderTransactionStates::STATE_FAILED;
    }

    private function getOrderTransaction(
        string $orderTransactionId,
        Context $context
    ): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        return $this->transactionOrderRepository->search($criteria, $context)->first();
    }

    /**
     * Get state if we have refunds
     *
     * @param SavedTransactionState $state
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return string
     */
    private function getRefundStatus(
        SavedTransactionState $state,
        OrderTransactionEntity $orderTransaction
    ): string {
        if ($this->isPartial($state->getAmount($state->getRefunds()), $orderTransaction)) {
            return OrderTransactionStates::STATE_PARTIALLY_REFUNDED;
        }
        return OrderTransactionStates::STATE_REFUNDED;
    }

    /**
     * Get state if we have payments
     *
     * @param SavedTransactionState $state
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return string
     */
    private function getPayStatus(
        SavedTransactionState $state,
        OrderTransactionEntity $orderTransaction
    ): string {
        if ($this->isPartial($state->getAmount($state->getPayments()), $orderTransaction)) {
            return OrderTransactionStates::STATE_PARTIALLY_PAID;
        }
        return OrderTransactionStates::STATE_PAID;
    }

    private function isPartial(
        float $amount,
        OrderTransactionEntity $orderTransaction
        ): bool
    {
        return round($orderTransaction->getOrder()->getAmountTotal(),2) > $amount;
    }
}