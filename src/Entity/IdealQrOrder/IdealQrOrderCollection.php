<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(IdealQrOrderEntity $entity)
 * @method void set(string $key, IdealQrOrderEntity $entity)
 * @method IdealQrOrderEntity[]     getIterator()
 * @method IdealQrOrderEntity[]     getElements()
 * @method null|IdealQrOrderEntity  get(string $key)
 * @method null|IdealQrOrderEntity  first()
 * @method null|IdealQrOrderEntity  last()
 */
class IdealQrOrderCollection extends EntityCollection
{
    /**
     * @return string
     */
    protected function getExpectedClass(): string
    {
        return IdealQrOrderEntity::class;
    }
}
