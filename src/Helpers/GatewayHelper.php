<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\PaymentMethods\Blik;
use Buckaroo\Shopware6\PaymentMethods\Eps;
use Buckaroo\Shopware6\PaymentMethods\In3;
use Buckaroo\Shopware6\PaymentMethods\Kbc;
use Buckaroo\Shopware6\PaymentMethods\P24;
use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\PaymentMethods\MBWay;
use Buckaroo\Shopware6\PaymentMethods\Alipay;
use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\PaymentMethods\Belfius;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Buckaroo\Shopware6\PaymentMethods\IdealQr;
use Buckaroo\Shopware6\PaymentMethods\Trustly;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Buckaroo\Shopware6\PaymentMethods\Klarnain;
use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Buckaroo\Shopware6\PaymentMethods\Payconiq;
use Buckaroo\Shopware6\PaymentMethods\Transfer;
use Buckaroo\Shopware6\PaymentMethods\Giftcards;
use Buckaroo\Shopware6\PaymentMethods\PayByBank;
use Buckaroo\Shopware6\PaymentMethods\WeChatPay;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Buckaroo\Shopware6\PaymentMethods\Creditcard;
use Buckaroo\Shopware6\PaymentMethods\Multibanco;
use Buckaroo\Shopware6\PaymentMethods\Creditcards;
use Buckaroo\Shopware6\PaymentMethods\PayPerEmail;
use Buckaroo\Shopware6\PaymentMethods\Knaken;
use Buckaroo\Shopware6\PaymentMethods\SepaDirectDebit;
use Buckaroo\Shopware6\PaymentMethods\Swish;
use Buckaroo\Shopware6\PaymentMethods\Bizum;
use Buckaroo\Shopware6\PaymentMethods\Twint;

class GatewayHelper
{
    public const GATEWAYS = [
        Ideal::class,
        Blik::class,
        Bancontact::class,
        Creditcard::class,
        Creditcards::class,
        AfterPay::class,
        Paypal::class,
        Transfer::class,
        ApplePay::class,
        Kbc::class,
        SepaDirectDebit::class,
        Payconiq::class,
        Giftcards::class,
        In3::class,
        Eps::class,
        P24::class,
        Alipay::class,
        WeChatPay::class,
        Trustly::class,
        Klarna::class,
        KlarnaKp::class,
        Klarnain::class,
        Billink::class,
        Belfius::class,
        PayPerEmail::class,
        PayByBank::class,
        IdealQr::class,
        MBWay::class,
        Multibanco::class,
        Knaken::class,
        Swish::class,
        Bizum::class,
        Twint::class
    ];
}
