<?php


declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1710940966DeleteIdealProcessing extends MigrationStep
{
    public const HANDLER_IDENTIFIER = 'Buckaroo\\Shopware6\\Handlers\\IdealProcessingPaymentHandler';

    public function getCreationTimestamp(): int
    {
        return 1710940966;
    }

    public function update(Connection $connection): void
    {
        // SQL statement to delete row from payment_method table
        $connection->executeStatement("
            DELETE FROM `payment_method`
            WHERE `handler_identifier` = :handlerIdentifier
        ", ['handlerIdentifier' => self::HANDLER_IDENTIFIER]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
