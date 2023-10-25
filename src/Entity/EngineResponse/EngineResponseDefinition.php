<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\EngineResponse;

use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;

class EngineResponseDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'buckaroo_engine_response';

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
        return EngineResponseEntity::class;
    }

    /**
     * @return string
     */
    public function getCollectionClass(): string
    {
        return EngineResponseCollection::class;
    }

    /**
     * @return FieldCollection
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('order_transaction_id', 'orderTransactionId', OrderTransactionDefinition::class))
                ->addFlags(new ApiAware()),
            new FloatField('amount', 'amount'),
            new StringField('type', 'type'),
            new StringField('transaction', 'transaction'),
            new StringField('transactionType', 'transactionType'),
            new StringField('relatedTransaction', 'relatedTransaction'),
            new StringField('serviceCode', 'serviceCode'),
            new StringField('statusCode', 'statusCode'),
            new StringField('status', 'status'),
            new BoolField('isTest', 'isTest'),
            new DateTimeField('createdByEngineAt', 'createdByEngineAt'),
            new LongTextField('customData', 'customData'),
            new StringField('signature', 'signature'),
            new ManyToOneAssociationField(
                'orderTransaction',
                'order_transaction_id',
                'id',
                OrderTransactionDefinition::class,
                false
            ),
        ]);
    }
}
