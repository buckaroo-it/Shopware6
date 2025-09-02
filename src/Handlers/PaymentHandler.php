<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

if (class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    class PaymentHandler extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        use PaymentHandlerModern;
    }
    return;
}

if (interface_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface::class) && !class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    class PaymentHandler implements \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
    {
        use PaymentHandlerLegacy;
    }
}
