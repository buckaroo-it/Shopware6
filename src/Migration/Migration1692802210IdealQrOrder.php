<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderDefinition;

class Migration1692802210IdealQrOrder extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1692802210;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('            
            CREATE TABLE IF NOT EXISTS `' . IdealQrOrderDefinition::ENTITY_NAME . '` (
                `id` BINARY(16) NOT NULL,
                `invoice` INT(11) NOT NULL AUTO_INCREMENT,
                `order_id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                INDEX `invoice_index` (`invoice`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
