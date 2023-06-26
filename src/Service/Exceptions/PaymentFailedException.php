<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;

class PaymentFailedException extends PaymentProcessException
{
    protected ?string $statusCode;

    public function getErrorCode(): string
    {
        return 'PAYMENT_FAILED_ERROR_' . $this->statusCode;
    }

    public function setPaymentStatusCode(?string $statusCode): void
    {
        if (!is_null($statusCode)) {
            $this->statusCode = '';
        }
        $this->statusCode = $statusCode;
    }
}
