<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Multibanco;

class MultibancoPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Multibanco::class;
}
