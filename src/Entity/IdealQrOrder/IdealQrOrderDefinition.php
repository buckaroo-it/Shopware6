<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class IdealQrOrderDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'buckaroo_ideal_qr_order';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return IdealQrOrderEntity::class;
    }

    public function getCollectionClass(): string
    {
        return IdealQrOrderCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new IntField('invoice', 'invoice'))->addFlags(new WriteProtected(), new ApiAware()),
            (new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new ReferenceVersionField(OrderDefinition::class))->addFlags(new Required(), new ApiAware()),
            (new FkField('order_transaction_id', 'orderTransactionId', OrderTransactionDefinition::class))
                ->addFlags(new Required(), new ApiAware()),
            (new ReferenceVersionField(OrderTransactionDefinition::class))->addFlags(new Required(), new ApiAware()),
            new OneToOneAssociationField('order', 'order_id', 'id', OrderDefinition::class, false),
            new OneToOneAssociationField(
                'orderTransaction',
                'order_transaction_id',
                'id',
                OrderTransactionDefinition::class,
                false
            ),
        ]);
    }
}
