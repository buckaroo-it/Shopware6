<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\OrderData;

use Buckaroo\Shopware6\Entity\OrderData\OrderDataEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(OrderDataEntity $entity)
 * @method void set(string $key, OrderDataEntity $entity)
 * @method OrderDataEntity[]     getIterator()
 * @method OrderDataEntity[]     getElements()
 * @method null|OrderDataEntity  get(string $key)
 * @method null|OrderDataEntity  first()
 * @method null|OrderDataEntity  last()
 * @extends EntityCollection<OrderDataEntity>
 */
class OrderDataCollection extends EntityCollection
{
    /**
     * @return string
     */
    protected function getExpectedClass(): string
    {
        return OrderDataEntity::class;
    }
}
