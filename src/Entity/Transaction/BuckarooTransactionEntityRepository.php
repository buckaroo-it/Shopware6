<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Class BuckarooTransactionEntityRepository
 */
class BuckarooTransactionEntityRepository
{
    private EntityRepository $baseRepository;

    /**
     * BuckarooTransactionEntityRepository constructor.
     */
    public function __construct(EntityRepository $baseRepository)
    {
        $this->baseRepository = $baseRepository;
    }

    /**
     * Create / update Buckaroo Transaction with required context for security
     *
     * @param array<mixed> $data
     * @param array<mixed> $additionalConditions
     * @param Context $context Required context for permission enforcement
     */
    public function save(?string $id, array $data, Context $context, array $additionalConditions = []): ?string
    {
        if ($id !== null) {
            /** @var BuckarooTransactionEntity $buckarooTransactionEntity|null */
            $buckarooTransactionEntity = $this->baseRepository
                ->search($this->buildCriteria($id, $additionalConditions), $context)
                ->getEntities()
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
     * Returns BuckarooTransactionEntity by its id with required context for security
     *
     * @param Context $context Required context for permission enforcement
     *
     * @throws InconsistentCriteriaIdsException
     */
    public function getById(string $id, Context $context): ?BuckarooTransactionEntity
    {
        /** @var BuckarooTransactionEntity|null */
        return $this->baseRepository
            ->search(new Criteria([$id]), $context)
            ->getEntities()
            ->first();
    }

    /**
     * Returns buckarooTransaction item with latest buckarooTransactionTimestamp with required context for security
     *
     * @param Context $context Required context for permission enforcement
     *
     * @throws InconsistentCriteriaIdsException
     */
    public function findLatestByType(string $type, Context $context): ?BuckarooTransactionEntity
    {
        $filter = ['type' => $type];
        $sortBy = ['buckarooTransactionTimestamp' => FieldSorting::DESCENDING];

        /** @var BuckarooTransactionEntity|null */
        return $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filter, $sortBy),
                $context
            )
            ->getEntities()
            ->first();
    }

    /**
     * Returns all buckarooTransaction items which satisfy given condition with required context for security
     *
     * @param array<mixed> $filterBy
     * @param array<mixed> $sortBy
     * @param int $start
     * @param int $limit
     * @param Context $context Required context for permission enforcement
     *
     * @throws InconsistentCriteriaIdsException
     *
     * @return BuckarooTransactionEntityCollection
     */
    public function findAll(
        Context $context,
        array $filterBy = [],
        array $sortBy = [],
        $start = 0,
        $limit = 10
    ): EntityCollection {
        /** @var BuckarooTransactionEntityCollection */
        $entities = $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filterBy, $sortBy, $limit, $start),
                $context
            )
            ->getEntities();

        return $entities;
    }

    /**
     * @param array<mixed> $sortBy
     * @param Context $context Required context for permission enforcement
     *
     * @return BuckarooTransactionEntityCollection
     */
    public function findByOrderId(string $id, Context $context, array $sortBy = []): EntityCollection
    {
        $filter = ['orderId' => $id];

        /** @var BuckarooTransactionEntityCollection */
        $entities = $this->baseRepository
            ->search(
                $this->buildCriteria(null, $filter, $sortBy),
                $context
            )
            ->getEntities();

        return $entities;
    }

    /**
     * Creates search criteria
     *
     * @param array<mixed> $additionalConditions
     * @param array<mixed> $sorting
     *
     * @throws InconsistentCriteriaIdsException
     */
    private function buildCriteria(
        ?string $id,
        array $additionalConditions,
        array $sorting = [],
        int $limit = 100,
        int $offset = 0
    ): Criteria {
        $ids = $id ? [$id] : null;
        $criteria = new Criteria($ids);
        foreach ($additionalConditions as $key => $value) {
            if (is_scalar($value)) {
                $criteria->addFilter(new EqualsFilter($key, $value));
            }
        }

        foreach ($sorting as $field => $direction) {
            if (!is_string($direction)) {
                continue;
            }
            $criteria->addSorting(new FieldSorting((string) $field, $direction));
        }

        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        return $criteria;
    }
}
