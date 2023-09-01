<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Refund;

use Buckaroo\Shopware6\Buckaroo\Refund\Order\PaymentRecord;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Buckaroo\Refund\RefundDataInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class OrderRefundData implements RefundDataInterface
{
    /**
     * @var OrderEntity
     */
    private OrderEntity $order;

    private float $amount;

    private PaymentRecord $paymentRecord;

    public function __construct(
        OrderEntity $order,
        PaymentRecord $paymentRecord,
        float $amount
        )
    {
        $this->order = $order;
        $this->amount = $amount;
        $this->paymentRecord = $paymentRecord;
    }
    public function getInvoiceNumber(): string
    {
        return $this->order->getOrderNumber();
    }

    public function getOrderNumber(): string
    {
        return $this->order->getOrderNumber();
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        $currency = $this->order->getCurrency();
        if ($currency === null) {
            throw new \InvalidArgumentException('Cannot find currency on order');
        }
        return $currency->getIsoCode();
    }

    public function getTransactionId(): string
    {
        return $this->getLastTransaction()->getId();
    }

    public function getOrderId(): string
    {
        return $this->order->getId();
    }

    public function getLastTransaction(): OrderTransactionEntity
    {
        $transactions = $this->order->getTransactions();

        if ($transactions === null) {
            throw new \InvalidArgumentException('Cannot find last transaction on order');
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        $transaction = $transactions->last();

        if ($transaction === null) {
            throw new \InvalidArgumentException('Cannot find last transaction on order');
        }

        return $transaction;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getPaymentRecord(): PaymentRecord
    {
        return $this->paymentRecord;
    }
}
