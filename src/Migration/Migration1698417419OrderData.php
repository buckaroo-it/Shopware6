<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Buckaroo\Shopware6\Entity\OrderData\OrderDataDefinition;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1698417419OrderData extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1698417419;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `' . OrderDataDefinition::ENTITY_NAME . '` (
                `id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16) NULL,
                `order_transaction_version_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NULL,
                `value` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                INDEX `name_index` (`name`),
                INDEX `order_transaction_id_index` (`order_transaction_id`),
                INDEX `order_transaction_version_id_index` (`order_transaction_version_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
