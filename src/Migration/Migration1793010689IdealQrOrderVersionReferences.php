<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderDefinition;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * order and order_transaction are version-aware, so the iDEAL QR order needs a
 * version reference next to each foreign key. Existing rows always point to the
 * live version: they are created during checkout, never on an order draft.
 *
 * @internal
 */
class Migration1793010689IdealQrOrderVersionReferences extends MigrationStep
{
    /**
     * @var array<string, string> version column => column it is placed after
     */
    private const VERSION_COLUMNS = [
        'order_version_id' => 'order_id',
        'order_transaction_version_id' => 'order_transaction_id',
    ];

    public function getCreationTimestamp(): int
    {
        return 1793010689;
    }

    public function update(Connection $connection): void
    {
        $table = IdealQrOrderDefinition::ENTITY_NAME;

        foreach (self::VERSION_COLUMNS as $column => $after) {
            if (!$this->columnExists($connection, $table, $column)) {
                $connection->executeStatement(
                    'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` BINARY(16) NULL AFTER `' . $after . '`'
                );
            }

            $connection->executeStatement(
                'UPDATE `' . $table . '` SET `' . $column . '` = :versionId WHERE `' . $column . '` IS NULL',
                ['versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );

            $connection->executeStatement(
                'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` BINARY(16) NOT NULL'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
