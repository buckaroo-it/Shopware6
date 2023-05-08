<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Buckaroo\Shopware6\Migration\Migration1590572335BuckarooTransaction;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;

/**
 * Class BuckarooTransactionEntityRepository
 */
class BuckarooTransactionEntityRepository
{
    /** * @var EntityRepositoryInterface */
    private $baseRepository;
    /** @var Connection */
    private $connection;
    /** * @var string */ 
    private $tableName;

    /**
     * BuckarooTransactionEntityRepository constructor.
     *
     * @param EntityRepositoryInterface $baseRepository
     * @param Connection $connection
     */
    public function __construct(EntityRepositoryInterface $baseRepository, Connection $connection)
    {
        $this->baseRepository = $baseRepository;
        $this->connection = $connection;
        $this->tableName = Migration1590572335BuckarooTransaction::TABLE;
    }

    /**
     * Create / update Buckaroo Transaction
     */
    public function save(?string $id, array $data, array $additionalConditions): ?string
    {
        $context = Context::createDefaultContext();
        /** @var BuckarooTransactionEntity $buckarooTransactionEntity */
        if ($id) {
            $buckarooTransactionEntity = $this->baseRepository->search($this->buildCriteria($id, $additionalConditions), $context)->first();
            if ($buckarooTransactionEntity) {
                $updateData = array_merge(['id' => $buckarooTransactionEntity->getId()], $data);
                $this->baseRepository->update([$updateData], $context);

                return $buckarooTransactionEntity->getId();
            }
        }

        $event = $this->baseRepository->create([$data], $context)->getEventByEntityName(BuckarooTransactionEntity::class);

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
        return $this->baseRepository->search(new Criteria([$id]), Context::createDefaultContext())->first();
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

        return $this->baseRepository->search($this->buildCriteria(null, $filter, $sortBy), Context::createDefaultContext())->first();
    }

    /**
     * Returns all buckarooTransaction items which satisfy given condition
     *
     * @param array $filterBy
     * @param array $sortBy
     * @param int $start
     * @param int $limit
     *
     * @return EntityCollection
     * @throws InconsistentCriteriaIdsException
     */
    public function findAll(array $filterBy = [], array $sortBy = [], $start = 0, $limit = 10): EntityCollection
    {
        return $this->baseRepository
            ->search($this->buildCriteria(null, $filterBy, $sortBy, $limit, $start), Context::createDefaultContext())
            ->getEntities();
    }

    /**
     * Creates search criteria
     *
     * @param string|null $id
     * @param array $additionalConditions
     *
     * @param array $sorting
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
    ): Criteria
    {
        $ids = $id ? [$id] : [];
        $criteria = new Criteria($ids);
        foreach ($additionalConditions as $key => $value) {
            $criteria->addFilter(new EqualsFilter($key, $value));
        }

        foreach ($sorting as $field => $direction) {
            $criteria->addSorting(new FieldSorting($field, $direction));
        }

        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        return $criteria;
    }

    public function findByOrderId(string $id, array $sortBy = []): EntityCollection
    {
        $filter = ['order_id' => $id];

        return $this->baseRepository->search($this->buildCriteria(null, $filter, $sortBy), Context::createDefaultContext())->getEntities();
    }
}
