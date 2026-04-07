<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1775224800RenameKlarnaLabelsToKlarna extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1775224800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            UPDATE `system_config`
            SET `configuration_value` = :newValue
            WHERE `configuration_key` = 'BuckarooPayments.config.klarnakpLabel'
        ", [
            'newValue' => json_encode(['_value' => 'Klarna']),
        ]);

        $connection->executeStatement("
            UPDATE `system_config`
            SET `configuration_value` = :newValue
            WHERE `configuration_key` = 'BuckarooPayments.config.klarnaLabel'
        ", [
            'newValue' => json_encode(['_value' => 'Klarna']),
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
