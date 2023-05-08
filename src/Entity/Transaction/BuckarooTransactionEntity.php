<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Class BuckarooTransactionEntity
 *
 * @package Buckaroo\Shopware6\Entity\Transaction
 */
class BuckarooTransactionEntity extends Entity
{
    use EntityIdTrait;

    private string $refundedItems;

    /**
     * @return array<mixed>
     */
    public function getRefundedItems(): array
    {
        $refundedItems = json_decode($this->refundedItems, true);
        if (is_array($refundedItems)) {
            return $refundedItems;
        }
        return [];
    }

    /**
     * @param array<mixed> $refundedItems
     *
     * @return self
     */
    public function setRefundedItems(array $refundedItems = []): self
    {
        $refundedItems = json_encode($refundedItems);
        if ($refundedItems !== false) {
            $this->refundedItems = $refundedItems;
        }
        return $this;
    }

    /**
     * @param array<mixed> $refundedItems
     *
     * @return self
     */
    public function addRefundedItems(array $refundedItems = []): self
    {
        $this->setRefundedItems(array_merge($this->getRefundedItems(), $refundedItems));
        return $this;
    }
}
