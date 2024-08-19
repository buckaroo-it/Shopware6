<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1724075767UpdateRivertyName extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1724075767;
    }
    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            UPDATE `system_config`
            SET `configuration_value` = :newValue
            WHERE `configuration_key` = :handlerIdentifier
        ", [
            'newValue' => json_encode(['_value' => 'Riverty']),
            'handlerIdentifier' => 'BuckarooPayments.config.afterpayLabel'
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement("
            UPDATE `system_config`
            SET `configuration_value` = :newValue
            WHERE `configuration_key` = :handlerIdentifier
        ", [
            'newValue' => json_encode(['_value' => 'Riverty | Afterpay']),
            'handlerIdentifier' => 'BuckarooPayments.config.afterpayLabel'
        ]);
    }
}
