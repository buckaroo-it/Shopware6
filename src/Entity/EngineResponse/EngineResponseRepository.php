<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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

    public function findByData(
        ?string $orderTransactionId,
        ?string $transactionKey,
        ?string $relatedKey,
        Context $context
    ): EngineResponseCollection {
        $criteria = new Criteria();

        $orFilters = [
            new EqualsFilter('transaction', $transactionKey),
            new EqualsFilter('transaction', $relatedKey),
            new EqualsFilter('relatedTransaction', $relatedKey),
            new EqualsFilter('relatedTransaction', $transactionKey),
        ];

        if ($orderTransactionId !== null) {
            $orFilters[] = new EqualsFilter('orderTransactionId', $orderTransactionId);
        }
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_OR,
                $orFilters
            )
        );
        /** @var EngineResponseCollection */
        return $this->entityRepository
            ->search($criteria, $context)
            ->getEntities();
    }

    public function findBySignature(
        ?string $signature,
        Context $context
    ): ?EngineResponseEntity {
        $criteria = new Criteria();

        $criteria->addFilter(
            new EqualsFilter('signature', $signature),
        );
        /** @var EngineResponseCollection */
        return $this->entityRepository
            ->search($criteria, $context)
            ->first();
    }
}