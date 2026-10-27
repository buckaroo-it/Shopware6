<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * "Automatically close open orders" became the switch "Cancel order when payment fails".
 * The former select stored "enabled"/"disabled"; convert those values to booleans for all
 * sales channels, so existing shops keep their choice and the switch shows it correctly.
 *
 * @internal
 */
class Migration1793059200ConvertCancelOrderSettingToSwitch extends MigrationStep
{
    public const CONFIG_KEY = 'BuckarooPayments.config.automaticallyCloseOpenOrders';

    public function getCreationTimestamp(): int
    {
        return 1793059200;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT `id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::CONFIG_KEY]
        );

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['configuration_value'], true);
            $value = is_array($decoded) ? ($decoded['_value'] ?? null) : null;

            if (!is_string($value)) {
                continue;
            }

            $connection->executeStatement(
                'UPDATE `system_config` SET `configuration_value` = :value WHERE `id` = :id',
                [
                    'value' => json_encode(['_value' => strtolower($value) === 'enabled']),
                    'id' => $row['id'],
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
