<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EngineResponseEntity extends Entity
{
    use EntityIdTrait;
    protected ?string $orderTransactionId;
    protected ?OrderTransactionEntity $orderTransaction;

    protected ?string $type;
    protected ?string $action;
    protected ?string $status;
    protected ?string $signature;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getSignature():?string 
    {
        return $this->signature;
    }

}
