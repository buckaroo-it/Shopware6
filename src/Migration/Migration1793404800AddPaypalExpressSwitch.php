<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * PayPal Express got an "Enable PayPal Express" switch, which replaces the "None" option of
 * "Display PayPal Express button". Per sales channel with its own pages: the switch is on when
 * a page is selected and off when only "None" is, so every shop keeps showing the button on the
 * same pages. "None" is removed from the stored pages.
 *
 * @internal
 */
class Migration1793404800AddPaypalExpressSwitch extends MigrationStep
{
    public const LOCATION_KEY = 'BuckarooPayments.config.paypalExpresslocation';

    public const ENABLED_KEY = 'BuckarooPayments.config.paypalExpressEnabled';

    public const PAGES = ['product', 'cart', 'checkout'];

    public function getCreationTimestamp(): int
    {
        return 1793404800;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT `id`, `configuration_value`, `sales_channel_id` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::LOCATION_KEY]
        );

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['configuration_value'], true);
            $locations = is_array($decoded) ? ($decoded['_value'] ?? null) : null;

            // An empty selection inherits from "All Sales Channels", so the switch inherits too
            if (!is_array($locations) || $locations === []) {
                continue;
            }

            $pages = array_values(array_intersect($locations, self::PAGES));

            $this->writeEnabled($connection, $row['sales_channel_id'], $pages !== []);

            if ($pages === []) {
                $connection->executeStatement('DELETE FROM `system_config` WHERE `id` = :id', ['id' => $row['id']]);
            } elseif ($pages !== $locations) {
                $connection->executeStatement(
                    'UPDATE `system_config` SET `configuration_value` = :value WHERE `id` = :id',
                    ['value' => json_encode(['_value' => $pages]), 'id' => $row['id']]
                );
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function writeEnabled(Connection $connection, ?string $salesChannelId, bool $enabled): void
    {
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            ['key' => self::ENABLED_KEY, 'salesChannelId' => $salesChannelId]
        );

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => self::ENABLED_KEY,
            'configuration_value' => json_encode(['_value' => $enabled]),
            'sales_channel_id' => $salesChannelId,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
