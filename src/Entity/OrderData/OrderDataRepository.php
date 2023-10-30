<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\OrderData;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Entity\OrderData\OrderDataEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

class OrderDataRepository
{
    public const ORDER_RETURN_STATUS_CODE = 'orderReturnStatusCode';

    private EntityRepository $entityRepository;

    public function __construct(EntityRepository $entityRepository)
    {
        $this->entityRepository = $entityRepository;
    }

    public function upsert(array $data, Context $context): EntityWrittenContainerEvent
    {
        return $this->entityRepository->upsert($data, $context);
    }

    public function setOrderReturnStatus(
        string $orderTransactionId,
        string $status,
        Context $context
    ): EntityWrittenContainerEvent {
        return $this->updateSingle(
            $orderTransactionId,
            self::ORDER_RETURN_STATUS_CODE,
            $status,
            $context
        );
    }

    private function updateSingle(
        string $orderTransactionId,
        string $name,
        string $value,
        Context $context
    ) {
        $data = [
            'orderTransactionId' => $orderTransactionId,
            'name' => $name,
            'value' => $value,
        ];

        $entity = $this->getSingle($orderTransactionId, $name, $context);
        if($entity !== null) {
            $data['id'] = $entity->getId();
        }
        return $this->upsert([$data], $context);
    }


    private function getSingle(
        string $orderTransactionId,
        string $name,
        Context $context
    ): ?OrderDataEntity {
        $criteria = new Criteria();
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_AND,
                [
                    new EqualsFilter('orderTransactionId', $orderTransactionId),
                    new EqualsFilter('name', $name),
                ]
            )
        );

        return $this->entityRepository->search($criteria, $context)->first();
    }

    public function getOrderReturnStatus(
        string $orderTransactionId,
        Context $context
    ): ?string {

        /** @var OrderDataEntity $result */
        $result = $this->getSingle(
            $orderTransactionId,
            self::ORDER_RETURN_STATUS_CODE,
            $context
        );
       
        if ($result === null) {
            return null;
        }
        return $result->getValue();
    }
}
