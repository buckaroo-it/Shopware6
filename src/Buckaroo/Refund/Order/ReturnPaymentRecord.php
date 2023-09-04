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
        return (string)$this->transactionData->get('id');
    }

    public function getAmount(): float
    {

        return (float)$this->transactionData->get('amount') - (float)$this->transactionData->get('amount_credit');
    }

    public function getOriginalTransactionId(): ?string
    {
        return $this->transactionData->get('order_transaction_id');
    }

    public function getPaymentCode(): ?string
    {
        return $this->transactionData->get('transaction_method');
    }

    public function addAmount(float $amount): self
    {
        $this->amount += $amount;
        return $this;
    }
}
