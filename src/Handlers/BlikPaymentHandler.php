<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Blik;

class BlikPaymentHandler extends PaymentHandler
{
    protected string $paymentClass = Blik::class;
}
