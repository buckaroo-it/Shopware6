<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(IdealQrOrderEntity $entity)
 * @method void set(string $key, IdealQrOrderEntity $entity)
 * @method IdealQrOrderEntity[] getIterator()
 * @method IdealQrOrderEntity[] getElements()
 * @method IdealQrOrderEntity|null get(string $key)
 * @method IdealQrOrderEntity|null first()
 * @method IdealQrOrderEntity|null last()
 *
 * @extends EntityCollection<IdealQrOrderEntity>
 */
class IdealQrOrderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return IdealQrOrderEntity::class;
    }
}
