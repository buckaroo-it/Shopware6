<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1669042629AddOrderIdIndexOnTransaction extends MigrationStep
{

    public const INDEX_NAME = 'order_id_index';

    public function getCreationTimestamp(): int
    {
        return 1669042629;
    }

    public function update(Connection $connection): void
    {
            $this->silentlyExecuteStatement(
                'ALTER TABLE ' . Migration1590572335BuckarooTransaction::TABLE . ' ADD INDEX ' . self::INDEX_NAME . ' (order_id)',
                $connection
            );
    }

    public function updateDestructive(Connection $connection): void
    {
        $this->silentlyExecuteStatement(
            'DROP INDEX ' . self::INDEX_NAME . ' ON ' . Migration1590572335BuckarooTransaction::TABLE,
            $connection
        );
    }
    
    private function silentlyExecuteStatement($sql, $connection) {
        try {
            $connection->executeStatement($sql);
        } catch (\Throwable $th) {
            //throw $th;
        }
    }
}
