<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;

class PaymentFailedException extends PaymentProcessException
{
    public function getErrorCode(): string
    {
        return 'PAYMENT_FAILED_ERROR';
    }
}
