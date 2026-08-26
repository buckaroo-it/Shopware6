<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1787734800RemoveGoSettle extends MigrationStep
{
    public const HANDLER_IDENTIFIER = 'Buckaroo\\Shopware6\\Handlers\\KnakenPaymentHandler';

    /**
     * Shopware 6.7 renamed `customer`.`default_payment_method_id` to `last_payment_method_id`,
     * so the column to check depends on the platform version the plugin runs on.
     *
     * @var string[]
     */
    private const CUSTOMER_PAYMENT_METHOD_COLUMNS = [
        'default_payment_method_id',
        'last_payment_method_id',
    ];

    public function getCreationTimestamp(): int
    {
        return 1787734800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "UPDATE `payment_method`
                SET `active` = 0
                WHERE `handler_identifier` = :handlerIdentifier",
            ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        $paymentMethodId = $connection->fetchOne(
            "SELECT `id` FROM `payment_method` WHERE `handler_identifier` = :handlerIdentifier",
            ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
        );

        if (!$paymentMethodId) {
            return;
        }

        $customerReferences = $this->countCustomerReferences($connection, $paymentMethodId);

        $orderTransactionReferences = $connection->fetchOne(
            "SELECT COUNT(*) FROM `order_transaction` WHERE `payment_method_id` = :paymentMethodId",
            ['paymentMethodId' => $paymentMethodId]
        );

        if ($customerReferences == 0 && $orderTransactionReferences == 0) {
            $connection->executeStatement(
                "DELETE FROM `payment_method` WHERE `handler_identifier` = :handlerIdentifier",
                ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
            );
        }
    }

    /**
     * @param mixed $paymentMethodId
     */
    private function countCustomerReferences(Connection $connection, $paymentMethodId): int
    {
        foreach (self::CUSTOMER_PAYMENT_METHOD_COLUMNS as $column) {
            if (!$this->hasColumn($connection, 'customer', $column)) {
                continue;
            }

            $count = $connection->fetchOne(
                "SELECT COUNT(*) FROM `customer` WHERE `{$column}` = :paymentMethodId",
                ['paymentMethodId' => $paymentMethodId]
            );

            return is_numeric($count) ? (int) $count : 0;
        }

        return 0;
    }

    private function hasColumn(Connection $connection, string $table, string $column): bool
    {
        return (bool) $connection->fetchOne(
            "SELECT COUNT(*)
                FROM `information_schema`.`COLUMNS`
                WHERE `TABLE_SCHEMA` = DATABASE()
                  AND `TABLE_NAME` = :table
                  AND `COLUMN_NAME` = :column",
            ['table' => $table, 'column' => $column]
        );
    }
}
