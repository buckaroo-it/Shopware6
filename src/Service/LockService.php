<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\Lock;
use Symfony\Component\Lock\LockFactory;

class LockerService
{
    public const LOCK_TTL = 300; //seconds
    private LockFactory $lockFactory;

    public function __construct(LockFactory $lockFactory)
    {
        $this->lockFactory = $lockFactory;
    }

    public function getLocker(string $orderTransactionId): Lock
    {
        return new Lock(
            $this->lockFactory->createLock(
                "bk-order-transaction-" . $orderTransactionId,
                self::LOCK_TTL,
                false
            )
        );
    }
}
