<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Payconiq;

class PayconiqPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Payconiq::class;
}
