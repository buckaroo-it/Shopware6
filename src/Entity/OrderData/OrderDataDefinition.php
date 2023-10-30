<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\OrderData;

use Buckaroo\Shopware6\Entity\OrderData\OrderDataEntity;
use Buckaroo\Shopware6\Entity\OrderData\OrderDataCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;

class OrderDataDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'buckaroo_order_transaction_data';

    /**
     * @return string
     */
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    /**
     * @return string
     */
    public function getEntityClass(): string
    {
        return OrderDataEntity::class;
    }

    /**
     * @return string
     */
    public function getCollectionClass(): string
    {
        return OrderDataCollection::class;
    }

    /**
     * @return FieldCollection
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('order_transaction_id', 'orderTransactionId', OrderTransactionDefinition::class)),
            (new ReferenceVersionField(OrderTransactionDefinition::class, 'order_transaction_version_id'))
                ->addFlags(new Required()),
            new StringField('name', 'name'),
            new LongTextField('value', 'value'),
            new ManyToOneAssociationField(
                'orderTransaction',
                'order_transaction_id',
                OrderTransactionDefinition::class,
                'id',
                false
            ),
        ]);
    }
}
