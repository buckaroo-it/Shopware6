<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1726736268RemoveGiropay extends MigrationStep
{
    public const HANDLER_IDENTIFIER = 'Buckaroo\\Shopware6\\Handlers\\GiropayPaymentHandler';
    public function getCreationTimestamp(): int
    {
        return 1726736268;
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

        $customerReferences = $connection->fetchOne(
            "SELECT COUNT(*) FROM `customer` WHERE `default_payment_method_id` = :paymentMethodId",
            ['paymentMethodId' => $paymentMethodId]
        );

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
}
