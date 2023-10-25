<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EngineResponseEntity extends Entity
{
    use EntityIdTrait;
    protected ?string $orderTransactionId;
    protected ?OrderTransactionEntity $orderTransaction;

    public function getOrderTransaction(): ?OrderTransactionEntity
    {
        return $this->orderTransaction;
    }
    public function setOrderTransaction(?OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
    public function getOrderTransactionId(): ?string
    {
        return $this->orderTransactionId;
    }
    public function setOrderTransactionId(?string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }
}
