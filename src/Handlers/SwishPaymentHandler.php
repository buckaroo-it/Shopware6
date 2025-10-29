<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Swish;

class SwishPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Swish::class;
}

