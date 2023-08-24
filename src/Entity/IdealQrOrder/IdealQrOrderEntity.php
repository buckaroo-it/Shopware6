<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class IdealQrOrderEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderId;
    protected ?OrderEntity $order;
    protected string $orderTransactionId;
    protected ?OrderTransactionEntity $orderTransaction;
    protected int $invoice;

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function setOrder(OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderTransaction(): OrderTransactionEntity
    {
        return $this->orderTransaction;
    }
    public function setOrderTransaction(OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }
    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }
    public function getInvoice(): int
    {
        return $this->invoice;
    }
}
