<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Visa;

class VisaPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Visa::class;
}
