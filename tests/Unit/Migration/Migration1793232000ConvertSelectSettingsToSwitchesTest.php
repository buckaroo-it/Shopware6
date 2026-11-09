<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793232000ConvertSelectSettingsToSwitches;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793232000ConvertSelectSettingsToSwitchesTest extends TestCase
{
    private const CHANNEL = 'sales-channel-bytes';

    /**
     * Existing shops keep their choice: the former select values become booleans,
     * values that are already booleans are left alone.
     */
    public function testConvertsFormerSelectValuesToBooleans(): void
    {
        $result = $this->runMigration([
            'BuckarooPayments.config.automaticallyCloseOpenOrders' => [
                $this->row('f', 'enabled'),
                $this->row('g', 'Disabled'),
                $this->row('h', 'Enabled'),
                $this->row('i', false),
            ],
            'BuckarooPayments.config.transferSendEmail' => [
                $this->row('a', 1),
                $this->row('b', '0'),
                $this->row('c', true),
            ],
            'BuckarooPayments.config.redirectBancontact' => [
                $this->row('d', 'enabledRedirect'),
                $this->row('e', 'disabledRedirect'),
            ],
        ]);

        $this->assertSame(
            ['f' => true, 'g' => false, 'h' => true, 'a' => true, 'b' => false, 'd' => true, 'e' => false],
            $result['written']
        );
        $this->assertSame([], $result['deleted']);
    }

    /**
     * Shopware ignored an empty value of a sales channel and used "All Sales Channels": that
     * row is removed so the channel keeps inheriting, instead of becoming an overriding false
     */
    public function testEmptySalesChannelValuesKeepInheriting(): void
    {
        $result = $this->runMigration([
            'BuckarooPayments.config.transferSendEmail' => [
                $this->row('global', 1),
                $this->row('channel-zero', '0', self::CHANNEL),
                $this->row('channel-int-zero', 0, self::CHANNEL),
                $this->row('channel-one', '1', self::CHANNEL),
            ],
        ]);

        $this->assertSame(['global' => true, 'channel-one' => true], $result['written']);
        $this->assertSame(['channel-zero', 'channel-int-zero'], $result['deleted']);
    }

    /**
     * @return array{id: string, configuration_value: string, sales_channel_id: string|null}
     */
    private function row(string $id, mixed $value, ?string $salesChannelId = null): array
    {
        return [
            'id' => $id,
            'configuration_value' => (string) json_encode(['_value' => $value]),
            'sales_channel_id' => $salesChannelId,
        ];
    }

    /**
     * @param array<string, array<int, array<string, string|null>>> $rowsPerKey
     * @return array{written: array<string, bool>, deleted: array<int, string>}
     */
    private function runMigration(array $rowsPerKey): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => $rowsPerKey[$params['key']] ?? []
        );

        $result = ['written' => [], 'deleted' => []];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$result): int {
                if (str_starts_with($sql, 'DELETE')) {
                    $result['deleted'][] = $params['id'];
                } else {
                    $result['written'][$params['id']] = json_decode($params['value'], true)['_value'];
                }
                return 1;
            }
        );

        (new Migration1793232000ConvertSelectSettingsToSwitches())->update($connection);

        return $result;
    }
}
