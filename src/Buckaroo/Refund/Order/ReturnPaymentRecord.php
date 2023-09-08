<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Refund\Order;

use Buckaroo\Shopware6\Buckaroo\Refund\Order\PaymentRecordInterface;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntity;

class ReturnPaymentRecord implements PaymentRecordInterface
{
    private BuckarooTransactionEntity $transactionData;

    private float $amount;

    public function __construct(BuckarooTransactionEntity $transactionData, float $amount)
    {
        $this->transactionData = $transactionData;
        $this->amount = $amount;
    }

    public function getId(): string
    {
        if (!is_scalar($this->transactionData->get('id'))) {
            return '';
        }
        return (string)$this->transactionData->get('id');
    }

    public function getAmount(): float
    {

        if (
            !is_scalar($this->transactionData->get('amount')) ||
            !is_scalar($this->transactionData->get('amount_credit'))
        ) {
            return 0.0;
        }
        return (float)$this->transactionData->get('amount') - (float)$this->transactionData->get('amount_credit');
    }

    public function getOriginalTransactionId(): ?string
    {
        if (
            !is_string($this->transactionData->get('transactions'))
        ) {
            return null;
        }
        return $this->transactionData->get('transactions');
    }

    public function getPaymentCode(): ?string
    {
        if (
            !is_string($this->transactionData->get('transaction_method'))
        ) {
            return null;
        }
        return $this->transactionData->get('transaction_method');
    }

    public function addAmount(float $amount): self
    {
        $this->amount += $amount;
        return $this;
    }
}
