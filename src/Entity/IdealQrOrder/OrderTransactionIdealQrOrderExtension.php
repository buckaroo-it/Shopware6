<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\IdealQrOrder;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;

/**
 * Reverse side of IdealQrOrderDefinition::orderTransaction, required by the DAL for
 * every one-to-one association. Not autoloaded and without delete flags, so loading,
 * versioning and deleting order transactions behave as before.
 */
class OrderTransactionIdealQrOrderExtension extends EntityExtension
{
    public const PROPERTY_NAME = 'buckarooIdealQrOrder';

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToOneAssociationField(
                self::PROPERTY_NAME,
                'id',
                'order_transaction_id',
                IdealQrOrderDefinition::class,
                false
            )
        );
    }

    public function getEntityName(): string
    {
        return OrderTransactionDefinition::ENTITY_NAME;
    }

    /**
     * Abstract on Shopware 6.5, replaced by getEntityName() in later versions.
     *
     * @return string
     */
    public function getDefinitionClass(): string
    {
        return OrderTransactionDefinition::class;
    }
}
