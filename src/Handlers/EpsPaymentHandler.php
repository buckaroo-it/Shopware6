<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Eps;

class EpsPaymentHandler extends PaymentHandlerSimple
{
    protected string $paymentClass = Eps::class;
}
