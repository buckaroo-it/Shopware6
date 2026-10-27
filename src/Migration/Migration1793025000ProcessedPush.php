<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * One row per push accepted for processing. The primary key on the push identity makes
 * claiming a push an insert that only one request can win.
 *
 * @internal
 */
class Migration1793025000ProcessedPush extends MigrationStep
{
    public const TABLE = 'buckaroo_processed_push';

    public function getCreationTimestamp(): int
    {
        return 1793025000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `push_hash` CHAR(64) NOT NULL,
                `order_transaction_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`push_hash`),
                INDEX `idx.buckaroo_processed_push.order_transaction_id` (`order_transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
