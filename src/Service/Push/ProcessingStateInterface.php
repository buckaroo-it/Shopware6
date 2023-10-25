<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

use Buckaroo\Shopware6\Service\Push\Request;
use Buckaroo\Shopware6\Service\Push\Transaction;

interface ProcessingStateInterface
{
    public function getRequest(): Request;

    public function setSkipped(bool $skipped = true): void;

    public function setStatus(string $status): void;

    public function addOrderData(array $data): void;

    public function setTransaction(Transaction $transaction): void;

    public function getTransaction(): ?Transaction;

    public function getStatus():?string;

    public function getOrderData(): array;
    
    public function isSkipped(): bool;

}
