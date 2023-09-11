<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;

class PaymentFailedException extends PaymentProcessException
{
    protected ?string $paymentStatusCode;


    public function __construct(
        string $orderTransactionId,
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
