<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

if (\class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    \class_alias(\Buckaroo\Shopware6\Handlers\Payments\Modern\IdealQrPaymentHandlerModern::class, \Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler::class);
} else {
    \class_alias(\Buckaroo\Shopware6\Handlers\Payments\Legacy\IdealQrPaymentHandlerLegacy::class, \Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler::class);
}


