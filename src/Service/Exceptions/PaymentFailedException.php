<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

use Shopware\Core\Checkout\Payment\PaymentException;

class PaymentFailedException extends PaymentException
{
    protected ?string $paymentStatusCode;

    public function __construct(
        int $orderTransactionId,
        string $message,
        array $parameters = [],
        ?\Throwable $e = null,
        string $paymentStatusCode = null
    ) {
        $this->paymentStatusCode = $paymentStatusCode;
        parent::__construct($orderTransactionId, $message, $parameters, $e);
    }
    public function getErrorCode(): string
    {
        return 'PAYMENT_FAILED_ERROR_' . (string)$this->paymentStatusCode;
    }
}
