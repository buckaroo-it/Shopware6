<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class IdealQrOrderRepository
{
    private EntityRepository $entityRepository;

    public function __construct(EntityRepository $entityRepository)
    {
        $this->entityRepository = $entityRepository;
    }

    public function create(
        OrderTransactionEntity $orderTransactionEntity,
        SalesChannelContext $salesChannelContext
    ): ?IdealQrOrderEntity {
        $result = $this->entityRepository->create([
            [
                'orderId' => $orderTransactionEntity->getOrderId(),
                'orderTransactionId' => $orderTransactionEntity->getId(),
            ]
        ], $salesChannelContext->getContext());

        $createdIds = $result->getPrimaryKeys(IdealQrOrderDefinition::ENTITY_NAME);

        // reset() yields false on an empty result, and Criteria([false]) is not
        // a valid id list. Nothing was created in that case, so report it the
        // same way a missing entity is reported.
        $primaryKey = reset($createdIds);
        if (!is_string($primaryKey)) {
            return null;
        }

        /** @var IdealQrOrderEntity|null */
        return $this->entityRepository
            ->search(new Criteria([$primaryKey]), $salesChannelContext->getContext())
            ->getEntities()
            ->first();
    }

    public function findByInvoice(
        int $invoice,
        SalesChannelContext $salesChannelContext
    ): ?IdealQrOrderEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('invoice', $invoice));

        /** @var IdealQrOrderEntity|null */
        return $this->entityRepository
            ->search($criteria, $salesChannelContext->getContext())
            ->first();
    }
}
