<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use DateTime;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EngineResponseEntity extends Entity
{
    use EntityIdTrait;
    protected ?string $orderTransactionId;
    protected ?OrderTransactionEntity $orderTransaction;

    protected ?string $type;
    protected ?string $status;
    protected ?string $signature;
    protected ?string $transaction;
    protected ?string $relatedTransaction;
    protected ?DateTime $createdByEngineAt;
    protected float $amount;

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getSignature():?string 
    {
        return $this->signature;
    }

    public function getTransactionKey():?string
    {
        return $this->transaction;
    }

    public function getRelatedTransaction(): ?string
    {
        return $this->relatedTransaction;
    }
    public function getCreatedByEngineAt(): ?DateTime {
        return $this->createdByEngineAt;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }
}
