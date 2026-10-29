<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * "Send payment instruction email" (Bank Transfer) and "Client-side encryption" (Bancontact)
 * became switches. The former selects stored 0/1 and "enabledRedirect"/"disabledRedirect";
 * convert those values to booleans for all sales channels, so existing shops keep their
 * choice and the switches show it correctly.
 *
 * @internal
 */
class Migration1793232000ConvertSelectSettingsToSwitches extends MigrationStep
{
    /**
     * Former select value that means "on", per setting
     */
    public const ENABLED_VALUES = [
        'BuckarooPayments.config.transferSendEmail'  => ['1'],
        'BuckarooPayments.config.redirectBancontact' => ['enabledredirect'],
    ];

    public function getCreationTimestamp(): int
    {
        return 1793232000;
    }

    public function update(Connection $connection): void
    {
        foreach (self::ENABLED_VALUES as $key => $enabledValues) {
            $rows = $connection->fetchAllAssociative(
                'SELECT `id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
                ['key' => $key]
            );

            foreach ($rows as $row) {
                $decoded = json_decode((string) $row['configuration_value'], true);
                $value = is_array($decoded) ? ($decoded['_value'] ?? null) : null;

                if (!is_string($value) && !is_int($value)) {
                    continue;
                }

                $connection->executeStatement(
                    'UPDATE `system_config` SET `configuration_value` = :value WHERE `id` = :id',
                    [
                        'value' => json_encode(['_value' => in_array(strtolower((string) $value), $enabledValues, true)]),
                        'id' => $row['id'],
                    ]
                );
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
