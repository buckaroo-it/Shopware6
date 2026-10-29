<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The Apple Pay and Google Pay switches "Show ... button on product page / in the cart /
 * on checkout page" became one "Display ... button" list, like PayPal Express.
 * Convert the switches per sales channel, so every shop keeps showing the button on
 * the same pages. A sales channel that overrode only some switches gets the pages it
 * inherited from "All Sales Channels" as well. The old values are left in place.
 *
 * @internal
 */
class Migration1793145600ConvertExpressButtonSwitchesToLocations extends MigrationStep
{
    private const PREFIX = 'BuckarooPayments.config.';

    /**
     * Old switch per page, for each payment method
     */
    public const PAGES = [
        'product'  => 'ShowProduct',
        'cart'     => 'ShowCart',
        'checkout' => 'ShowCheckout',
    ];

    public const METHODS = ['applepay', 'googlepay'];

    public function getCreationTimestamp(): int
    {
        return 1793145600;
    }

    public function update(Connection $connection): void
    {
        foreach (self::METHODS as $method) {
            $this->convertMethod($connection, $method);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function convertMethod(Connection $connection, string $method): void
    {
        $keys = [];
        foreach (self::PAGES as $page => $suffix) {
            $keys[self::PREFIX . $method . $suffix] = $page;
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT `configuration_key`, `configuration_value`, LOWER(HEX(`sales_channel_id`)) AS `sales_channel_id`
             FROM `system_config` WHERE `configuration_key` IN (?, ?, ?)',
            array_keys($keys)
        );

        // [salesChannelId or '' for "All Sales Channels"][page] => bool
        $values = [];
        foreach ($rows as $row) {
            $salesChannelId = (string) ($row['sales_channel_id'] ?? '');
            $page = $keys[$row['configuration_key']];
            $values[$salesChannelId][$page] = $this->toBool($row['configuration_value']);
        }

        $global = $values[''] ?? [];

        foreach ($values as $salesChannelId => $own) {
            $locations = [];
            foreach (array_keys(self::PAGES) as $page) {
                if ($own[$page] ?? $global[$page] ?? false) {
                    $locations[] = $page;
                }
            }

            $this->writeLocations(
                $connection,
                self::PREFIX . $method . 'Expresslocation',
                $salesChannelId === '' ? null : $salesChannelId,
                $locations === [] ? ['none'] : $locations
            );
        }
    }

    /**
     * Same interpretation as the storefront used for the old switches
     */
    private function toBool(mixed $configurationValue): bool
    {
        $decoded = json_decode((string) $configurationValue, true);
        $value = is_array($decoded) ? ($decoded['_value'] ?? null) : null;

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return $value === '1' || strtolower($value) === 'true';
        }

        return false;
    }

    /**
     * @param array<string> $locations
     */
    private function writeLocations(Connection $connection, string $key, ?string $salesChannelId, array $locations): void
    {
        // Replaces the default Shopware writes for new settings before plugin migrations run
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            [
                'key' => $key,
                'salesChannelId' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId),
            ]
        );

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $key,
            'configuration_value' => json_encode(['_value' => $locations]),
            'sales_channel_id' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
