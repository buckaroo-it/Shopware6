<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Entity\ProcessedPush\ProcessedPushDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Records which pushes were accepted for processing. A push is claimed with a single
 * insert on its identity, so of two concurrent copies of the same push only one passes.
 */
class ProcessedPushService
{
    public const TABLE = ProcessedPushDefinition::ENTITY_NAME;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Claim the push, or return false when it was already claimed.
     */
    public function claim(string $pushKey, string $orderTransactionId): bool
    {
        try {
            $this->connection->insert(self::TABLE, [
                'push_hash' => $this->hash($pushKey),
                'order_transaction_id' => Uuid::fromHexToBytes($orderTransactionId),
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Forget a claimed push, so the same push can be processed again.
     */
    public function release(string $pushKey): void
    {
        $this->connection->delete(self::TABLE, ['push_hash' => $this->hash($pushKey)]);
    }

    private function hash(string $pushKey): string
    {
        return hash('sha256', $pushKey);
    }
}
