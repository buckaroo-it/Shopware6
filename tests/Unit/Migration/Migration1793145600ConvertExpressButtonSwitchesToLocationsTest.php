<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793145600ConvertExpressButtonSwitchesToLocations;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793145600ConvertExpressButtonSwitchesToLocationsTest extends TestCase
{
    private const SALES_CHANNEL = '98432def39fc4624b33213a56b8c944d';

    /**
     * Existing shops must keep showing the button on the same pages: a sales channel
     * that overrode one switch still gets the pages it inherited from "All Sales Channels".
     */
    public function testConvertsSwitchesPerSalesChannelIncludingInheritedPages(): void
    {
        $written = $this->runMigration([
            'applepay' => [
                $this->row('applepayShowProduct', true),
                $this->row('applepayShowCart', 0),
                $this->row('applepayShowCheckout', '1'),
                $this->row('applepayShowCart', true, self::SALES_CHANNEL),
                $this->row('applepayShowCheckout', 'false', self::SALES_CHANNEL),
            ],
            'googlepay' => [
                $this->row('googlepayShowProduct', false),
                $this->row('googlepayShowCart', 0),
            ],
        ], 3);

        $this->assertSame([
            'BuckarooPayments.config.applepayExpresslocation|' => ['product', 'checkout'],
            'BuckarooPayments.config.applepayExpresslocation|' . self::SALES_CHANNEL => ['product', 'cart'],
            'BuckarooPayments.config.googlepayExpresslocation|' => ['none'],
        ], $written);
    }

    public function testWritesNothingWhenTheSwitchesWereNeverSaved(): void
    {
        $this->assertSame([], $this->runMigration(['applepay' => [], 'googlepay' => []], 0));
    }

    /**
     * @param array<string, array<int, array<string, string|null>>> $rowsPerMethod
     * @param int $expectedWrites number of settings the migration writes
     * @return array<string, array<int, string>> "key|salesChannelId" => locations
     */
    private function runMigration(array $rowsPerMethod, int $expectedWrites): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($rowsPerMethod): array {
                $method = str_replace(['BuckarooPayments.config.', 'ShowProduct'], '', $params[0]);
                return $rowsPerMethod[$method];
            }
        );

        $written = [];
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$written): int {
                $salesChannelId = $data['sales_channel_id'] === null ? '' : bin2hex($data['sales_channel_id']);
                $written[$data['configuration_key'] . '|' . $salesChannelId] = json_decode($data['configuration_value'], true)['_value'];
                return 1;
            }
        );
        $connection->expects($this->exactly($expectedWrites))
            ->method('executeStatement')
            ->willReturn(0);

        (new Migration1793145600ConvertExpressButtonSwitchesToLocations())->update($connection);

        return $written;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(string $key, mixed $value, ?string $salesChannelId = null): array
    {
        return [
            'configuration_key' => 'BuckarooPayments.config.' . $key,
            'configuration_value' => json_encode(['_value' => $value]),
            'sales_channel_id' => $salesChannelId,
        ];
    }
}
