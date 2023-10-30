<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Buckaroo\Shopware6\Migration\Migration1590572335BuckarooTransaction;

/**
 * Class BuckarooTransactionEntityDefinition
 */
class BuckarooTransactionEntityDefinition extends EntityDefinition
{
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getEntityName(): string
    {
        return Migration1590572335BuckarooTransaction::TABLE;
    }

    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getEntityClass(): string
    {
        return BuckarooTransactionEntity::class;
    }

    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getCollectionClass(): string
    {
        return BuckarooTransactionEntityCollection::class;
    }

    /**
     * @inheritDoc
     *
     * @return FieldCollection
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new  PrimaryKey(), new Required()),
            new StringField('order_id', 'order_id'),
            new StringField('order_transaction_id', 'order_transaction_id'),
            new StringField('amount', 'amount'),
            new StringField('amount_credit', 'amount_credit'),
            new StringField('currency', 'currency'),
            new StringField('ordernumber', 'ordernumber'),
            new StringField('statuscode', 'statuscode'),
            new StringField('transaction_method', 'transaction_method'),
            new StringField('transaction_type', 'transaction_type'),
            new StringField('transactions', 'transactions'),
            new StringField('relatedtransaction', 'relatedtransaction'),
            new StringField('type', 'type'),
            new LongTextField('refunded_items', 'refunded_items'),
            new DateTimeField('created_at', 'created_at'),
            new DateTimeField('updated_at', 'updated_at'),
        ]);
    }

    /**
     * Do not add timestamps as default fields
     *
     * @return array<mixed>
     */
    protected function defaultFields(): array
    {
        return [];
    }
}
