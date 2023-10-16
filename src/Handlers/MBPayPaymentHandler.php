<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\MBPay;

class MBPayPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = MBPay::class;
}
