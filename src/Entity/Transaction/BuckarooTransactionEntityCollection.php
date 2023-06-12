<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Class BuckarooTransactionEntityCollection
 * @template TElement of BuckarooTransactionEntity
 * @extends EntityCollection<TElement>
 */
class BuckarooTransactionEntityCollection extends EntityCollection
{
    /**
     * @inheritDoc
     *
     * @return string
     */
    protected function getExpectedClass(): string
    {
        return BuckarooTransactionEntity::class;
    }
}
