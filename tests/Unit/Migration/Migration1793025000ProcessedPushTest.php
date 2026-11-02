<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793025000ProcessedPush;
use Buckaroo\Shopware6\Service\ProcessedPushService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793025000ProcessedPushTest extends TestCase
{
    public function testCreatesTheProcessedPushTableWithThePushAsUniqueKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->callback(static function (string $sql): bool {
                return str_contains($sql, 'CREATE TABLE IF NOT EXISTS `' . ProcessedPushService::TABLE . '`')
                    && str_contains($sql, 'PRIMARY KEY (`push_hash`)');
            }))
            ->willReturn(0);

        (new Migration1793025000ProcessedPush())->update($connection);
    }

    public function testMigrationAndServiceUseTheSameTable(): void
    {
        $this->assertSame(Migration1793025000ProcessedPush::TABLE, ProcessedPushService::TABLE);
    }
}
