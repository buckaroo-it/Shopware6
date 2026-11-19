<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1795046400RemovePayconiq;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1795046400RemovePayconiqTest extends TestCase
{
    private const PAYCONIQ_ID = 'payconiq-id';

    private const OTHER_METHOD_ID = 'other-method-id';

    /**
     * @var array<int, array{0: string, 1: array<string, mixed>}>
     */
    private array $statements = [];

    /**
     * Payconiq is deactivated and removed from every sales channel (also from the copy of the
     * assigned IDs on the sales channel), a sales channel with Payconiq
     * as default gets another active payment method, and the settings go. The payment method
     * itself is not deleted, because orders refer to it.
     */
    public function testDeactivatesAndUnassignsPayconiqWithoutDeletingIt(): void
    {
        $connection = $this->connection(self::PAYCONIQ_ID, ['channel-a', 'channel-b'], [
            'channel-a' => self::OTHER_METHOD_ID,
            'channel-b' => false,
        ]);

        (new Migration1795046400RemovePayconiq())->update($connection);

        $this->assertStatement('UPDATE `payment_method` SET `active` = 0 WHERE `id` = :paymentMethodId', [
            'paymentMethodId' => self::PAYCONIQ_ID,
        ]);
        $this->assertStatement(
            'DELETE FROM `sales_channel_payment_method` WHERE `payment_method_id` = :paymentMethodId',
            ['paymentMethodId' => self::PAYCONIQ_ID]
        );
        $this->assertStatement(
            'UPDATE `sales_channel` SET `payment_method_id` = :replacementId WHERE `id` = :salesChannelId',
            ['replacementId' => self::OTHER_METHOD_ID, 'salesChannelId' => 'channel-a']
        );
        $this->assertStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => 'BuckarooPayments.config.payconiq%']
        );
        $jsonUpdates = array_filter(
            $this->statements,
            static fn (array $statement): bool => str_contains($statement[0], 'SET `payment_method_ids` = JSON_REMOVE(')
                && $statement[1] === ['paymentMethodId' => self::PAYCONIQ_ID]
        );
        $this->assertCount(1, $jsonUpdates, 'The copy of the assigned payment method IDs drops Payconiq too');
        $this->assertCount(5, $this->statements, 'channel-b has no other active payment method and is left alone');

        foreach ($this->statements as [$sql]) {
            $this->assertStringNotContainsString('DELETE FROM `payment_method`', $sql);
        }
    }

    /**
     * A shop that never had Payconiq only loses the (absent) settings
     */
    public function testWithoutPayconiqOnlyTheSettingsAreDeleted(): void
    {
        (new Migration1795046400RemovePayconiq())->update($this->connection(false, [], []));

        $this->assertSame(
            [[
                'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
                ['prefix' => 'BuckarooPayments.config.payconiq%'],
            ]],
            $this->statements
        );
    }

    public function testPayconiqIsGoneFromTheConfigAndGateways(): void
    {
        $root = __DIR__ . '/../../../src';

        $this->assertStringNotContainsStringIgnoringCase(
            'payconiq',
            (string) file_get_contents($root . '/Resources/config/config.xml')
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'payconiq',
            (string) file_get_contents($root . '/Helpers/GatewayHelper.php')
        );
        $this->assertFileDoesNotExist($root . '/Handlers/PayconiqPaymentHandler.php');
        $this->assertFileDoesNotExist($root . '/PaymentMethods/Payconiq.php');
    }

    /**
     * @param string|false $payconiqId
     * @param string[] $defaultChannels sales channels with Payconiq as default payment method
     * @param array<string, string|false> $replacements active payment method each sales channel still offers
     */
    private function connection($payconiqId, array $defaultChannels, array $replacements): Connection
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params) use ($payconiqId, $replacements) {
                if (str_contains($sql, '`handler_identifier` = :handlerIdentifier')) {
                    $this->assertSame(
                        'Buckaroo\\Shopware6\\Handlers\\PayconiqPaymentHandler',
                        $params['handlerIdentifier']
                    );

                    return $payconiqId;
                }

                return $replacements[$params['salesChannelId']] ?? false;
            }
        );

        $connection->method('fetchFirstColumn')->willReturn($defaultChannels);

        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                $this->statements[] = [$sql, $params];

                return 1;
            }
        );

        return $connection;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertStatement(string $sql, array $params): void
    {
        $this->assertContains([$sql, $params], $this->statements);
    }
}
