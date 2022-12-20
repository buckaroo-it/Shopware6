<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Belfius;

class BelfiusPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Belfius::class;

}
