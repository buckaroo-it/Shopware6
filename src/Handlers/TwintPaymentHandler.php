<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Twint;

class TwintPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Twint::class;
}
