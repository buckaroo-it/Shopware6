<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Class BuckarooTransactionEntityCollection
 *
 * @extends EntityCollection<BuckarooTransactionEntity>
 */
class BuckarooTransactionEntityCollection extends EntityCollection
{
    /**
     * {@inheritDoc}
     */
    protected function getExpectedClass(): string
    {
        return BuckarooTransactionEntity::class;
    }
}
