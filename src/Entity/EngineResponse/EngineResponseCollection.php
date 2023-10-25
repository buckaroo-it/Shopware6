<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(EngineResponseEntity $entity)
 * @method void set(string $key, EngineResponseEntity $entity)
 * @method EngineResponseEntity[]     getIterator()
 * @method EngineResponseEntity[]     getElements()
 * @method null|EngineResponseEntity  get(string $key)
 * @method null|EngineResponseEntity  first()
 * @method null|EngineResponseEntity  last()
 * @extends EntityCollection<EngineResponseEntity>
 */
class EngineResponseCollection extends EntityCollection
{
    /**
     * @return string
     */
    protected function getExpectedClass(): string
    {
        return EngineResponseEntity::class;
    }
}
