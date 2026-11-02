<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Entity;

use Buckaroo\Shopware6\Entity\ProcessedPush\ProcessedPushCollection;
use Buckaroo\Shopware6\Entity\ProcessedPush\ProcessedPushDefinition;
use Buckaroo\Shopware6\Entity\ProcessedPush\ProcessedPushEntity;
use Buckaroo\Shopware6\Migration\Migration1793025000ProcessedPush;
use Buckaroo\Shopware6\Service\ProcessedPushService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

/**
 * bin/console dal:validate reported 'Table "buckaroo_processed_push" has no configured
 * definition'. The definition has to mirror the migration exactly: the push hash is the
 * only primary key, and there is no updated_at column.
 */
class ProcessedPushDefinitionTest extends TestCase
{
    public function testDefinitionNamesTheMigratedTable(): void
    {
        $definition = new ProcessedPushDefinition();

        static::assertSame(Migration1793025000ProcessedPush::TABLE, ProcessedPushDefinition::ENTITY_NAME);
        static::assertSame(ProcessedPushService::TABLE, $definition->getEntityName());
        static::assertSame(ProcessedPushEntity::class, $definition->getEntityClass());
        static::assertSame(ProcessedPushCollection::class, $definition->getCollectionClass());
    }

    public function testFieldsMatchTheMigratedColumns(): void
    {
        $fields = [];
        foreach ($this->fields(new ProcessedPushDefinition()) as $field) {
            $fields[$field->getStorageName()] = $field;
        }

        static::assertSame(['push_hash', 'order_transaction_id', 'created_at'], array_keys($fields));
        static::assertInstanceOf(StringField::class, $fields['push_hash']);
        static::assertInstanceOf(IdField::class, $fields['order_transaction_id']);
        static::assertInstanceOf(CreatedAtField::class, $fields['created_at']);
    }

    public function testThePushHashIsTheOnlyPrimaryKey(): void
    {
        $primaryKeys = [];
        foreach ($this->fields(new ProcessedPushDefinition()) as $field) {
            if ($field->is(PrimaryKey::class)) {
                $primaryKeys[] = $field->getStorageName();
            }
        }

        static::assertSame(['push_hash'], $primaryKeys);
    }

    public function testDefinitionIsRegisteredAsEntityDefinition(): void
    {
        $services = (string) file_get_contents(__DIR__ . '/../../../src/Resources/config/services.yaml');

        static::assertMatchesRegularExpression(
            '/Buckaroo\\\\Shopware6\\\\Entity\\\\ProcessedPush\\\\ProcessedPushDefinition:\s+tags:\s+'
            . '- shopware\.entity\.definition: \{ entity: buckaroo_processed_push \}/',
            $services
        );
    }

    public function testEntityExposesTheColumns(): void
    {
        $entity = new ProcessedPushEntity();
        $entity->setPushHash(str_repeat('a', 64));
        $entity->setOrderTransactionId('0190b6d3e8c87a5b9c2f1d4e5a6b7c8d');

        static::assertSame(str_repeat('a', 64), $entity->getPushHash());
        static::assertSame('0190b6d3e8c87a5b9c2f1d4e5a6b7c8d', $entity->getOrderTransactionId());
    }

    /**
     * getFields() needs a compiled definition registry, so read the two sources it merges directly.
     *
     * @return list<Field>
     */
    private function fields(ProcessedPushDefinition $definition): array
    {
        $fields = [];
        foreach ((new \ReflectionMethod($definition, 'defineFields'))->invoke($definition) as $field) {
            if ($field instanceof Field) {
                $fields[] = $field;
            }
        }
        foreach ((new \ReflectionMethod($definition, 'defaultFields'))->invoke($definition) as $field) {
            if ($field instanceof Field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
