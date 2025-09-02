<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

if (\interface_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface::class)) {
    \class_alias(\Buckaroo\Shopware6\Handlers\Payments\Legacy\PaypalPaymentHandlerLegacy::class, \Buckaroo\Shopware6\Handlers\PaypalPaymentHandler::class);
} else {
    \class_alias(\Buckaroo\Shopware6\Handlers\Payments\Modern\PaypalPaymentHandlerModern::class, \Buckaroo\Shopware6\Handlers\PaypalPaymentHandler::class);
}