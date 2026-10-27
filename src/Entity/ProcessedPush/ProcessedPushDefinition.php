<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\ProcessedPush;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Describes the buckaroo_processed_push table to the DAL so dal:validate knows it.
 * The table is written through ProcessedPushService with a plain Connection; the
 * hash of the push identity is its primary key, not a generated id.
 */
class ProcessedPushDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'buckaroo_processed_push';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ProcessedPushEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ProcessedPushCollection::class;
    }

    /**
     * The table only has created_at, so the default updated_at field must not be added.
     *
     * @return list<\Shopware\Core\Framework\DataAbstractionLayer\Field\Field>
     */
    protected function defaultFields(): array
    {
        return [new CreatedAtField()];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new StringField('push_hash', 'pushHash', 64))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('order_transaction_id', 'orderTransactionId'))->addFlags(new Required()),
        ]);
    }
}
