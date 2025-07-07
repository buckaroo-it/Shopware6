<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1746016954RemoveBuckarooPrefixForAllPayments extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1746016954;
    }

    public function update(Connection $connection): void
    {
        // 1. Update `system_config` labels
        $connection->executeStatement("
            UPDATE system_config
            SET configuration_value = JSON_SET(
                configuration_value,
                '$._value',
                TRIM(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(configuration_value, '$._value')), 'Buckaroo ', ''))
            )
            WHERE configuration_key LIKE 'BuckarooPayments.config.%Label'
              AND JSON_UNQUOTE(JSON_EXTRACT(configuration_value, '$._value')) LIKE '%Buckaroo%';
        ");

        // 2. Clean up `name` field
        $connection->executeStatement("
            UPDATE payment_method_translation
            SET name = TRIM(REPLACE(name, 'Buckaroo ', ''))
            WHERE name LIKE '%Buckaroo%';
        ");

        // 3. Clean up `distinguishable_name` field
        $connection->executeStatement("
            UPDATE payment_method_translation
            SET distinguishable_name = TRIM(
                REPLACE(
                    REPLACE(distinguishable_name, 'Buckaroo ', ''),
                    '| Buckaroo Payment', ''
                )
            )
            WHERE distinguishable_name LIKE '%Buckaroo%';
        ");

        // 4. Clean up `description` field
        $connection->executeStatement("
            UPDATE payment_method_translation
            SET description = TRIM(
                REPLACE(
                    REPLACE(description, 'Buckaroo ', ''),
                    '| Buckaroo Payment', ''
                )
            )
            WHERE description LIKE '%Buckaroo%';
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
