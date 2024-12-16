<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1733844659RemoveSofort extends MigrationStep
{
    public const HANDLER_IDENTIFIER = 'Buckaroo\\Shopware6\\Handlers\\SofortPaymentHandler';
    public function getCreationTimestamp(): int
    {
        return 1733844659;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "DELETE FROM `payment_method`
                WHERE `handler_identifier` = :handlerIdentifier",
            ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
        );
    }
    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement(
            "DELETE FROM `payment_method`
                WHERE `handler_identifier` = :handlerIdentifier",
            ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
        );
    }
}
