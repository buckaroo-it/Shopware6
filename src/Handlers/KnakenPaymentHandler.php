<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Knaken;

class KnakenPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Knaken::class;
}
