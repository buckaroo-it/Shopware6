<?php declare(strict_types=1);

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

    private $refundedItems;

    public function getRefundedItems()
    {
        $refundedItems = json_decode($this->refundedItems, true);
        return $refundedItems ?: [];
    }

    /**
     * @param array $refundedItems
     */
    public function setRefundedItems(array $refundedItems = [])
    {
        $this->refundedItems = json_encode($refundedItems);
        return $this;
    }

    /**
     * @param array $refundedItems
     */
    public function addRefundedItems(array $refundedItems = [])
    {
        $this->setRefundedItems(array_merge($this->getRefundedItems(), $refundedItems));
        return $this;
    }
}
