<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Refund\Order;

use Buckaroo\Shopware6\Buckaroo\Refund\Order\PaymentRecordInterface;

class PaymentRecord implements PaymentRecordInterface
{
    private array $transactionData;

    public function __construct(array $transactionData)
    {
        $this->transactionData = $transactionData;
    }

    public function getId(): string
    {
        if (
            !isset($this->transactionData['id']) ||
            !is_scalar($this->transactionData['id'])
        ) {
            throw new \InvalidArgumentException('Transaction id must be a string');
        }
        return (string)$this->transactionData['id'];
    }

    public function getAmount(): float
    {
        if (
            !isset($this->transactionData['amount']) ||
            !is_scalar($this->transactionData['amount'])
        ) {
            return 0;
        }
        return (float)$this->transactionData['amount'];
    }

    public function getOriginalTransactionId(): ?string
    {
        if (
            !isset($this->transactionData['transactions']) ||
            !is_string($this->transactionData['transactions'])
        ) {
            return null;
        }
        return $this->transactionData['transactions'];
    }

    public function getPaymentCode(): ?string
    {
        if (
            !isset($this->transactionData['transaction_method']) &&
            !is_string($this->transactionData['transaction_method'])
        ) {
            return null;
        }
        return $this->transactionData['transaction_method'];
    }
}
