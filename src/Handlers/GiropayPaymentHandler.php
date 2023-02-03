<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Giropay;

class GiropayPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Giropay::class;
}
