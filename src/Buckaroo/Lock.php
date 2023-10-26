<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Symfony\Component\Lock\LockInterface;

class Lock
{
    private LockInterface $locker;

    public function __construct(LockInterface $locker) {
        $this->locker = $locker;
    }

    public function acquire(): bool
    {
        return $this->locker->acquire(true);
    }
    public function release(): void
    {
        $this->locker->release();
    }
}