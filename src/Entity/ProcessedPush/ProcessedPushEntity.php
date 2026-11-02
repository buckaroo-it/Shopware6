<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\ProcessedPush;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

class ProcessedPushEntity extends Entity
{
    protected string $pushHash;
    protected string $orderTransactionId;

    public function getPushHash(): string
    {
        return $this->pushHash;
    }

    public function setPushHash(string $pushHash): void
    {
        $this->pushHash = $pushHash;
    }

    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }
}
