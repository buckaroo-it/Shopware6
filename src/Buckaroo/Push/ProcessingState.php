<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;

class ProcessingState implements ProcessingStateInterface
{
    private Request $request;

    private ?string $status = null;

    private array $orderData = [];

    private ?Transaction $transaction = null;

    private bool $isSkipped = true;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setStatus(string $status = null): void
    {
        $this->status = $status;
    }

    public function addOrderData(array $data): void
    {
        $this->orderData = array_merge_recursive(
            $this->orderData,
            $data
        );
    }

    public function setSkipped(bool $skipped = true): void
    {
        $this->isSkipped = $skipped;
    }

    public function setTransaction(?Transaction $transaction): void
    {
        $this->transaction = $transaction;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function isSkipped(): bool
    {
        return $this->isSkipped;
    }
}
