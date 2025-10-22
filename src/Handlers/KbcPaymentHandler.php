<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Kbc;

class KbcPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Kbc::class;
}
