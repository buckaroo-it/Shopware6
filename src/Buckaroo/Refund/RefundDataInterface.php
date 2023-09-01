<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Refund;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Buckaroo\Refund\Order\PaymentRecordInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

interface RefundDataInterface
{
    public function getInvoiceNumber(): string;

    public function getOrderNumber(): string;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function getTransactionId(): string;

    public function getOrderId(): string;

    public function getOrder(): OrderEntity;

    public function getPaymentRecord(): PaymentRecordInterface;

    public function getLastTransaction(): OrderTransactionEntity;
}
