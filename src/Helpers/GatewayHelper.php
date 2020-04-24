<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\PaymentMethods\IdealProcessing;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Buckaroo\Shopware6\PaymentMethods\Creditcards;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Shopware6\PaymentMethods\Sofort;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\PaymentMethods\Transfer;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Buckaroo\Shopware6\PaymentMethods\Giropay;
use Buckaroo\Shopware6\PaymentMethods\Kbc;

class GatewayHelper
{
    public const GATEWAYS = [
        Ideal::class,
        IdealProcessing::class,
        Bancontact::class,
        Creditcards::class,
        AfterPay::class,
        Sofort::class,
        Paypal::class,
        Transfer::class,
        ApplePay::class,
        Giropay::class,
        Kbc::class
    ];
}
