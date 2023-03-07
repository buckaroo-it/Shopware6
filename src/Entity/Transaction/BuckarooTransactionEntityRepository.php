<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;

/**
 * Class BuckarooTransactionEntityRepository
 */
class BuckarooTransactionEntityRepository
{
    /** * @var EntityRepository */
    private EntityRepository $baseRepository;

    /**
     * BuckarooTransactionEntityRepository constructor.
     *
     * @param EntityRepository $baseRepository
     */
    public function __construct(EntityRepository $baseRepository)
    {
        $this->baseRepository = $baseRepository;
    }


    /**
     * Create / update Buckaroo Transaction
     *
     * @param string|null $id
     * @param array<mixed> $data,
     * @param array<mixed> $additionalConditions
     *
     * @return string|null
     */
    public function save(?string $id, array $data, array $additionalConditions = []): ?string
    {
        $context = Context::createDefaultContext();
        if ($id !== null) {
            /** @var BuckarooTransactionEntity $buckarooTransactionEntity|null */
            $buckarooTransactionEntity = $this->baseRepository
                ->search($this->buildCriteria($id, $additionalConditions), $context)
                ->first();

            if ($buckarooTransactionEntity !== null) {
                $updateData = array_merge(['id' => $buckarooTransactionEntity->getId()], $data);
                $this->baseRepository->update([$updateData], $context);

                return $buckarooTransactionEntity->getId();
            }
        }

        $event = $this->baseRepository
            ->create([$data], $context)
            ->getEventByEntityName(BuckarooTransactionEntity::class);

        return $event ? $event->getIds()[0] : null;
    }

    /**
     * Returns BuckarooTransactionEntity by its id
     *
     * @param string $id
     *
     * @return BuckarooTransactionEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    public function getById(string $id): ?BuckarooTransactionEntity
    {
        return $this->baseRepository
            ->search(new Criteria([$id]), Context::createDefaultContext())
            ->first();
    }

    /**
     * Returns buckarooTransaction item with latest buckarooTransactionTimestamp
     *
     * @param string $type
     *
     * @return BuckarooTransactionEntity|null
     * @throws InconsistentCriteriaIdsException
     */
    public function findLatestByType(string $type): ?BuckarooTransactionEntity
    {
        $filter = ['type' => $type];
        $sortBy = ['buckarooTransactionTimestamp' => FieldSorting::DESCENDING];

        return $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filter, $sortBy),
                Context::createDefaultContext()
            )
            ->first();
    }

    /**
     * Returns all buckarooTransaction items which satisfy given condition
     *
     * @param array<mixed> $filterBy
     * @param array<mixed> $sortBy
     * @param int $start
     * @param int $limit
     *
     * @return BuckarooTransactionEntityCollection<BuckarooTransactionEntity>
     * @throws InconsistentCriteriaIdsException
     */
    public function findAll(
        array $filterBy = [],
        array $sortBy = [],
        $start = 0,
        $limit = 10
    ): EntityCollection {
        /** @var BuckarooTransactionEntityCollection<BuckarooTransactionEntity> */
        $entities = $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filterBy, $sortBy, $limit, $start),
                Context::createDefaultContext()
            )
            ->getEntities();
        return $entities;
    }

    /**
     * Creates search criteria
     *
     * @param string|null $id
     * @param array<mixed> $additionalConditions
     *
     * @param array<mixed> $sorting
     * @param int $limit
     * @param int $offset
     *
     * @return Criteria
     * @throws InconsistentCriteriaIdsException
     */
    private function buildCriteria(
        ?string $id,
        array $additionalConditions,
        array $sorting = [],
        int $limit = 100,
        int $offset = 0
    ): Criteria {
        $ids = $id ? [$id] : [];
        $criteria = new Criteria($ids);
        foreach ($additionalConditions as $key => $value) {
            $criteria->addFilter(new EqualsFilter($key, $value));
        }

        foreach ($sorting as $field => $direction) {
            if (!is_string($direction)) {
                continue;
            }
            $criteria->addSorting(new FieldSorting((string)$field, $direction));
        }

        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        return $criteria;
    }

    /**
     * @param string $id
     * @param array<mixed> $sortBy
     *
     * @return BuckarooTransactionEntityCollection<BuckarooTransactionEntity>
     */
    public function findByOrderId(string $id, array $sortBy = []): EntityCollection
    {
        $filter = ['order_id' => $id];

        /** @var BuckarooTransactionEntityCollection<BuckarooTransactionEntity> */
        $entities = $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filter, $sortBy),
                Context::createDefaultContext()
            )
            ->getEntities();
        return $entities;
    }
}
