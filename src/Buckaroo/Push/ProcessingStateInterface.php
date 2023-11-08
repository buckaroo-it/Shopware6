<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;

interface ProcessingStateInterface
{
    public function getRequest(): Request;

    public function setSkipped(bool $skipped = true): void;

    public function setStatus(?string $status): void;

    public function addOrderData(array $data): void;

    public function setType(string $type): void;

    public function getTransactionData(): array;

    public function getStatus():?string;

    public function getOrderData(): array;
}
