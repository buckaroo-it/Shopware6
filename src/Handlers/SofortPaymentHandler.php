<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Sofort;

class SofortPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Sofort::class;
}
