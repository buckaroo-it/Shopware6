<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\ProcessedPush;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(ProcessedPushEntity $entity)
 * @method void set(string $key, ProcessedPushEntity $entity)
 * @method ProcessedPushEntity[] getIterator()
 * @method ProcessedPushEntity[] getElements()
 * @method ProcessedPushEntity|null get(string $key)
 * @method ProcessedPushEntity|null first()
 * @method ProcessedPushEntity|null last()
 *
 * @extends EntityCollection<ProcessedPushEntity>
 */
class ProcessedPushCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProcessedPushEntity::class;
    }
}
