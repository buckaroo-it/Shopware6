<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\MBWay;

class MBWayPaymentHandler extends PaymentHandlerSimple
{
    protected string $paymentClass = MBWay::class;
}
