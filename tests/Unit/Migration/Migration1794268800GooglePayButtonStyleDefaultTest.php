<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1794268800GooglePayButtonStyleDefault;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1794268800GooglePayButtonStyleDefaultTest extends TestCase
{
    /**
     * "Default" was drawn as a black button, so it becomes Black, for "All Sales Channels" and
     * for every sales channel. Black and White are left alone.
     */
    public function testDefaultBecomesBlack(): void
    {
        $written = $this->runMigration([
            $this->row('global', 'default'),
            $this->row('channel-default', 'default'),
            $this->row('channel-white', 'white'),
            $this->row('channel-black', 'black'),
        ]);

        $this->assertSame(['global' => 'black', 'channel-default' => 'black'], $written);
    }

    public function testLeavesUnreadableValuesAlone(): void
    {
        $written = $this->runMigration([
            ['id' => 'empty', 'configuration_value' => '{"_value":""}'],
            ['id' => 'broken', 'configuration_value' => 'not json'],
            ['id' => 'null', 'configuration_value' => null],
        ]);

        $this->assertSame([], $written);
    }

    /**
     * @return array{id: string, configuration_value: string}
     */
    private function row(string $id, string $value): array
    {
        return ['id' => $id, 'configuration_value' => (string) json_encode(['_value' => $value])];
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     * @return array<string, mixed> written value per row id
     */
    private function runMigration(array $rows): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($rows): array {
                return $params['key'] === Migration1794268800GooglePayButtonStyleDefault::KEY ? $rows : [];
            }
        );

        $written = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$written): int {
                $written[$params['id']] = json_decode($params['value'], true)['_value'];
                return 1;
            }
        );

        (new Migration1794268800GooglePayButtonStyleDefault())->update($connection);

        return $written;
    }
}
