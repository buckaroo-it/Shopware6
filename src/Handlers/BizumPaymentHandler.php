<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Bizum;

class BizumPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Bizum::class;
}
