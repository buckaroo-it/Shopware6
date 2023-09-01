<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Refund\Order;

interface PaymentRecordInterface
{
    public function getId(): string;

    public function getAmount(): float;

    public function getOriginalTransactionId(): ?string;

    public function getPaymentCode(): ?string;
}
