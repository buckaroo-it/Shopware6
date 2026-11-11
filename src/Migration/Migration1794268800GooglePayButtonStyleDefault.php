<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The Google Pay button style no longer has a "Default" option: only Black and White, with Black
 * as the default. The storefront already drew "Default" as a black button, so a stored "default"
 * becomes "black" (per sales channel) and the button looks the same after the update.
 *
 * @internal
 */
class Migration1794268800GooglePayButtonStyleDefault extends MigrationStep
{
    public const KEY = 'BuckarooPayments.config.googlepayButtonStyle';

    public function getCreationTimestamp(): int
    {
        return 1794268800;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT `id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::KEY]
        );

        foreach ($rows as $row) {
            if ($this->storedValue($row['configuration_value']) !== 'default') {
                continue;
            }

            $connection->executeStatement(
                'UPDATE `system_config` SET `configuration_value` = :value, `updated_at` = :updatedAt WHERE `id` = :id',
                [
                    'value' => json_encode(['_value' => 'black']),
                    'updatedAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'id' => $row['id'],
                ]
            );
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
