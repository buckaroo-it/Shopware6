<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793404800AddPaypalExpressSwitch;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793404800AddPaypalExpressSwitchTest extends TestCase
{
    /**
     * Existing shops keep showing the button on the same pages: a selected page turns the
     * switch on, "None" turns it off and is removed, an empty selection keeps inheriting.
     */
    public function testConvertsNoneToSwitchPerSalesChannel(): void
    {
        $result = $this->runMigration([
            ['id' => 'global', 'sales_channel_id' => null, 'configuration_value' => json_encode(['_value' => ['none']])],
            ['id' => 'shop-a', 'sales_channel_id' => 'a', 'configuration_value' => json_encode(['_value' => ['product', 'cart']])],
            ['id' => 'shop-b', 'sales_channel_id' => 'b', 'configuration_value' => json_encode(['_value' => ['none', 'checkout']])],
            ['id' => 'shop-c', 'sales_channel_id' => 'c', 'configuration_value' => json_encode(['_value' => []])],
        ]);

        $this->assertSame(['' => false, 'a' => true, 'b' => true], $result['enabled']);
        $this->assertSame(['global'], $result['deleted']);
        $this->assertSame(['shop-b' => ['checkout']], $result['updated']);
    }

    /**
     * @param array<int, array<string, string|null|false>> $rows
     * @return array{enabled: array<string, bool>, deleted: array<int, string>, updated: array<string, array<int, string>>}
     */
    private function runMigration(array $rows): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $result = ['enabled' => [], 'deleted' => [], 'updated' => []];

        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$result): int {
                $result['enabled'][(string) $data['sales_channel_id']] = json_decode($data['configuration_value'], true)['_value'];
                return 1;
            }
        );

        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$result): int {
                if (str_starts_with($sql, 'UPDATE')) {
                    $result['updated'][$params['id']] = json_decode($params['value'], true)['_value'];
                } elseif (isset($params['id'])) {
                    $result['deleted'][] = $params['id'];
                }
                return 1;
            }
        );

        (new Migration1793404800AddPaypalExpressSwitch())->update($connection);

        return $result;
    }
}
