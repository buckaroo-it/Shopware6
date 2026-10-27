<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\ProcessedPushService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * A push is claimed with one insert on its identity: the unique key, not a read before
 * the write, decides which of two copies of the same push is processed.
 */
class ProcessedPushServiceTest extends TestCase
{
    private const PUSH_KEY = 'KEY-123|190|C021|PROCESSING';

    public function testFirstClaimOfAPushSucceeds(): void
    {
        $orderTransactionId = Uuid::randomHex();

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                ProcessedPushService::TABLE,
                $this->callback(static function (array $row) use ($orderTransactionId): bool {
                    return $row['push_hash'] === hash('sha256', self::PUSH_KEY)
                        && $row['order_transaction_id'] === Uuid::fromHexToBytes($orderTransactionId)
                        && is_string($row['created_at']);
                })
            )
            ->willReturn(1);

        $this->assertTrue((new ProcessedPushService($connection))->claim(self::PUSH_KEY, $orderTransactionId));
    }

    public function testClaimOfAnAlreadyClaimedPushFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(
            new UniqueConstraintViolationException($this->createMock(DriverException::class), null)
        );

        $this->assertFalse((new ProcessedPushService($connection))->claim(self::PUSH_KEY, Uuid::randomHex()));
    }

    /**
     * Any other database error is not a duplicate: it is passed on, so the push fails and
     * Buckaroo retries it.
     */
    public function testOtherDatabaseErrorsAreNotSwallowed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('database gone'));

        $this->expectException(\RuntimeException::class);

        (new ProcessedPushService($connection))->claim(self::PUSH_KEY, Uuid::randomHex());
    }

    public function testReleaseRemovesTheClaim(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with(ProcessedPushService::TABLE, ['push_hash' => hash('sha256', self::PUSH_KEY)])
            ->willReturn(1);

        (new ProcessedPushService($connection))->release(self::PUSH_KEY);
    }
}
