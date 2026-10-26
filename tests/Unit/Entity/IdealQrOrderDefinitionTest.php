<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Entity;

use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderDefinition;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderEntity;
use Buckaroo\Shopware6\Entity\IdealQrOrder\OrderIdealQrOrderExtension;
use Buckaroo\Shopware6\Entity\IdealQrOrder\OrderTransactionIdealQrOrderExtension;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityDefinition;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\RestrictDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * bin/console dal:validate aborted on the missing ENTITY_NAME constant of the transaction
 * definition. Behind that crash it reported the iDEAL QR order definition: every
 * one-to-one association needs a reverse side, and a foreign key to the version-aware
 * order / order_transaction needs a version reference next to it.
 */
class IdealQrOrderDefinitionTest extends TestCase
{
    public function testTransactionDefinitionDeclaresEntityNameConstant(): void
    {
        static::assertSame('buckaroo_transaction', BuckarooTransactionEntityDefinition::ENTITY_NAME);
        static::assertSame(
            BuckarooTransactionEntityDefinition::ENTITY_NAME,
            (new BuckarooTransactionEntityDefinition())->getEntityName()
        );
    }

    public function testDefinitionHasVersionReferenceForOrderAndOrderTransaction(): void
    {
        $versionFields = [];
        foreach ($this->defineFields(new IdealQrOrderDefinition()) as $field) {
            if ($field instanceof ReferenceVersionField) {
                $versionFields[$field->getStorageName()] = $field->getVersionReferenceClass();
            }
        }

        static::assertSame(
            [
                'order_version_id' => OrderDefinition::class,
                'order_transaction_version_id' => OrderTransactionDefinition::class,
            ],
            $versionFields
        );
    }

    /**
     * @return iterable<string, array{EntityExtension, string, string}>
     */
    public static function extensionProvider(): iterable
    {
        yield 'order' => [new OrderIdealQrOrderExtension(), OrderDefinition::ENTITY_NAME, 'order_id'];
        yield 'order transaction' => [
            new OrderTransactionIdealQrOrderExtension(),
            OrderTransactionDefinition::ENTITY_NAME,
            'order_transaction_id',
        ];
    }

    /**
     * @dataProvider extensionProvider
     */
    public function testExtensionAddsReverseOneToOneAssociation(
        EntityExtension $extension,
        string $entityName,
        string $referenceField
    ): void {
        static::assertSame($entityName, $extension->getEntityName());

        $collection = new FieldCollection();
        $extension->extendFields($collection);

        static::assertCount(1, $collection);
        $field = $collection->first();
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
        static::assertSame('buckarooIdealQrOrder', $field->getPropertyName());
        // dal:validate matches the reverse side on storage name and reference definition
        static::assertSame('id', $field->getStorageName());
        static::assertSame($referenceField, $field->getReferenceField());
        static::assertSame(IdealQrOrderDefinition::class, $field->getReferenceClass());
        // must not change how orders are loaded, versioned or deleted
        static::assertFalse($field->getAutoload());
        static::assertFalse($field->is(CascadeDelete::class));
        static::assertFalse($field->is(RestrictDelete::class));
        static::assertFalse($field->is(SetNullOnDelete::class));
    }

    public function testEntityExposesVersionIds(): void
    {
        $entity = new IdealQrOrderEntity();
        $entity->setOrderVersionId('order-version');
        $entity->setOrderTransactionVersionId('transaction-version');

        static::assertSame('order-version', $entity->getOrderVersionId());
        static::assertSame('transaction-version', $entity->getOrderTransactionVersionId());
    }

    private function defineFields(IdealQrOrderDefinition $definition): FieldCollection
    {
        $method = new \ReflectionMethod($definition, 'defineFields');

        /** @var FieldCollection */
        return $method->invoke($definition);
    }
}
