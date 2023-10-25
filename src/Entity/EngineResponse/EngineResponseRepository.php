<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

class EngineResponseRepository
{
    private EntityRepository $entityRepository;

    public function __construct(EntityRepository $entityRepository)
    {
        $this->entityRepository = $entityRepository;
    }

    public function upsert(array $data, Context $context): EntityWrittenContainerEvent
    {
        return $this->entityRepository->upsert($data, $context);
    }
}
