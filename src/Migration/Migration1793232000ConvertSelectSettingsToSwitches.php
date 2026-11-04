<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * "Cancel order when payment fails", "Send payment instruction email" (Bank Transfer) and
 * "Client-side encryption" (Bancontact) became switches. Their former selects stored
 * "enabled"/"disabled", 0/1 and "enabledRedirect"/"disabledRedirect"; convert those values
 * to booleans for all sales channels, so existing shops keep their choice and the switches
 * show it correctly. Values that are already booleans are left alone.
 *
 * @internal
 */
class Migration1793232000ConvertSelectSettingsToSwitches extends MigrationStep
{
    /**
     * Stored values that meant "on" before the update, per setting (compared case-insensitively).
     * The previous code only acted on "enabled", 1 and "enabledRedirect", so other values such as
     * "true" were off and stay off.
     */
    public const ENABLED_VALUES = [
        'BuckarooPayments.config.automaticallyCloseOpenOrders' => ['enabled'],
        'BuckarooPayments.config.transferSendEmail'            => ['1'],
        'BuckarooPayments.config.redirectBancontact'           => ['enabledredirect'],
    ];

    public function getCreationTimestamp(): int
    {
        return 1793232000;
    }

    public function update(Connection $connection): void
    {
        foreach (self::ENABLED_VALUES as $key => $enabledValues) {
            $rows = $connection->fetchAllAssociative(
                'SELECT `id`, `configuration_value`, `sales_channel_id` FROM `system_config` WHERE `configuration_key` = :key',
                ['key' => $key]
            );

            foreach ($rows as $row) {
                $value = $this->storedValue($row['configuration_value']);

                if (!is_string($value) && !is_int($value)) {
                    continue;
                }

                // Shopware ignores an empty value (0, "0", "") of a sales channel and uses the value of
                // "All Sales Channels"; a boolean false would override it, so keep it inheriting instead
                if ($row['sales_channel_id'] !== null && empty($value)) {
                    $connection->executeStatement('DELETE FROM `system_config` WHERE `id` = :id', ['id' => $row['id']]);
                    continue;
                }

                $connection->executeStatement(
                    'UPDATE `system_config` SET `configuration_value` = :value, `updated_at` = :updatedAt WHERE `id` = :id',
                    [
                        'value' => json_encode(['_value' => in_array(strtolower((string) $value), $enabledValues, true)]),
                        'updatedAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                        'id' => $row['id'],
                    ]
                );
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function storedValue(mixed $configurationValue): mixed
    {
        $decoded = is_string($configurationValue) ? json_decode($configurationValue, true) : null;

        return is_array($decoded) ? ($decoded['_value'] ?? null) : null;
    }
}
