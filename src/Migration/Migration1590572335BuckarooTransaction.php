<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1590572335BuckarooTransaction extends MigrationStep
{
    public const TABLE = 'buckaroo_transaction';

    public function getCreationTimestamp(): int
    {
        return 1590572335;
    }

    public function update(Connection $connection): void
    {
        $connection->exec('            
            CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `id` BINARY(16) NOT NULL,
                
                `order_id` VARCHAR(255),
                `order_transaction_id` VARCHAR(255),
                `amount` VARCHAR(255),
                `amount_credit` VARCHAR(255),
                `currency` VARCHAR(255),
                `ordernumber` VARCHAR(255),
                `statuscode` VARCHAR(255),
                `transaction_method` VARCHAR(255),
                `transaction_type` VARCHAR(255),
                `transactions` VARCHAR(255),
                `relatedtransaction` VARCHAR(255),
                `type` VARCHAR(255),
                `refunded_items` LONGTEXT,
                
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                
                PRIMARY KEY (`id`)

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
