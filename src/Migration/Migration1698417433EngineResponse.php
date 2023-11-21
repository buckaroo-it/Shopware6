<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseDefinition;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1698417433EngineResponse extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1698417433;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
        CREATE TABLE IF NOT EXISTS `" . EngineResponseDefinition::ENTITY_NAME . "` (
            `id` BINARY(16) NOT NULL,
            `order_transaction_id` BINARY(16) NULL,
            `order_transaction_version_id` BINARY(16) NOT NULL,
            `amount` DOUBLE NULL,
            `type` VARCHAR(255) NULL,
            `transaction` VARCHAR(255) NULL,
            `transactionType` VARCHAR(255) NULL,
            `relatedTransaction` VARCHAR(255) NULL,
            `serviceCode` VARCHAR(255) NULL,
            `statusCode` VARCHAR(255) NULL,
            `status` VARCHAR(255) NULL,
            `createdByEngineAt` DATETIME(3) DEFAULT NULL,
            `customData` LONGTEXT NULL,
            `signature` VARCHAR(255) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            INDEX `transaction_index` (`transaction`),
            INDEX `relatedTransaction_index` (`relatedTransaction`),
            PRIMARY KEY (`id`),
            INDEX `order_transaction_id_index` (`order_transaction_id`),
            INDEX `order_transaction_version_id_index` (`order_transaction_version_id`),
            INDEX `signature_index` (`signature`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
