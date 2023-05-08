<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Bancontact;

class BancontactPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Bancontact::class;
}
