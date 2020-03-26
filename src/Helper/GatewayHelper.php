<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helper;

use Buckaroo\Shopware6\PaymentMethods\Visa;

class GatewayHelper
{
    public const GATEWAYS = [
        Visa::class
    ];
}
