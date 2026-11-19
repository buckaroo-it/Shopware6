<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Billink's "Create invoice after shipment" setting is removed: it has had no effect since
 * authorize and capture were removed for Billink, and the invoice is always created after a
 * successful payment. Its stored values (for "All Sales Channels" and every sales channel) go too.
 *
 * @internal
 */
class Migration1794787200RemoveBillinkCreateInvoiceAfterShipment extends MigrationStep
{
    public const KEY = 'BuckarooPayments.config.BillinkCreateInvoiceAfterShipment';

    public function getCreationTimestamp(): int
    {
        return 1794787200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::KEY]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
