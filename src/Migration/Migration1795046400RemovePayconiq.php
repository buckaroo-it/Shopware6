<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Payconiq is no longer offered: the payment method is deactivated and removed from every
 * sales channel, and its stored settings are deleted. The payment method itself stays, because
 * existing orders refer to it.
 *
 * A sales channel that has Payconiq as its default payment method gets another active payment
 * method it offers as default, so the sales channel stays valid in the Administration.
 *
 * @internal
 */
class Migration1795046400RemovePayconiq extends MigrationStep
{
    public const HANDLER_IDENTIFIER = 'Buckaroo\\Shopware6\\Handlers\\PayconiqPaymentHandler';

    public const CONFIG_KEY_PREFIX = 'BuckarooPayments.config.payconiq';

    public function getCreationTimestamp(): int
    {
        return 1795046400;
    }

    public function update(Connection $connection): void
    {
        $paymentMethodId = $connection->fetchOne(
            'SELECT `id` FROM `payment_method` WHERE `handler_identifier` = :handlerIdentifier',
            ['handlerIdentifier' => self::HANDLER_IDENTIFIER]
        );

        if (is_string($paymentMethodId) && $paymentMethodId !== '') {
            $connection->executeStatement(
                'UPDATE `payment_method` SET `active` = 0 WHERE `id` = :paymentMethodId',
                ['paymentMethodId' => $paymentMethodId]
            );

            $connection->executeStatement(
                'DELETE FROM `sales_channel_payment_method` WHERE `payment_method_id` = :paymentMethodId',
                ['paymentMethodId' => $paymentMethodId]
            );

            // Shopware keeps a copy of the assigned payment method IDs on the sales channel
            $connection->executeStatement(
                'UPDATE `sales_channel`
                    SET `payment_method_ids` = JSON_REMOVE(
                        `payment_method_ids`,
                        JSON_UNQUOTE(JSON_SEARCH(`payment_method_ids`, \'one\', LOWER(HEX(:paymentMethodId))))
                    )
                    WHERE JSON_CONTAINS(`payment_method_ids`, JSON_QUOTE(LOWER(HEX(:paymentMethodId))))',
                ['paymentMethodId' => $paymentMethodId]
            );

            $this->replaceSalesChannelDefault($connection, $paymentMethodId);
        }

        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => self::CONFIG_KEY_PREFIX . '%']
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function replaceSalesChannelDefault(Connection $connection, string $paymentMethodId): void
    {
        $salesChannelIds = $connection->fetchFirstColumn(
            'SELECT `id` FROM `sales_channel` WHERE `payment_method_id` = :paymentMethodId',
            ['paymentMethodId' => $paymentMethodId]
        );

        foreach ($salesChannelIds as $salesChannelId) {
            $replacementId = $connection->fetchOne(
                'SELECT `payment_method`.`id`
                    FROM `sales_channel_payment_method`
                    INNER JOIN `payment_method`
                        ON `payment_method`.`id` = `sales_channel_payment_method`.`payment_method_id`
                    WHERE `sales_channel_payment_method`.`sales_channel_id` = :salesChannelId
                      AND `payment_method`.`active` = 1
                    ORDER BY `payment_method`.`position` ASC
                    LIMIT 1',
                ['salesChannelId' => $salesChannelId]
            );

            // No other active payment method in this sales channel: leave it for the merchant to choose
            if (!is_string($replacementId) || $replacementId === '') {
                continue;
            }

            $connection->executeStatement(
                'UPDATE `sales_channel` SET `payment_method_id` = :replacementId WHERE `id` = :salesChannelId',
                ['replacementId' => $replacementId, 'salesChannelId' => $salesChannelId]
            );
        }
    }
}
