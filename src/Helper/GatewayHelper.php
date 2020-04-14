<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helper;

use Buckaroo\Shopware6\PaymentMethods\Visa;
use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\PaymentMethods\IdealProcessing;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Buckaroo\Shopware6\PaymentMethods\Creditcards;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Shopware6\PaymentMethods\Sofort;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\PaymentMethods\Transfer;

class GatewayHelper
{
    public const GATEWAYS = [
        Visa::class,
        Ideal::class,
        IdealProcessing::class,
        Bancontact::class,
        Creditcards::class,
        AfterPay::class,
        Sofort::class,
        Paypal::class,
        Transfer::class
    ];
}
