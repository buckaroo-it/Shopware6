<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

// This file aliases the appropriate implementation to the common class name
if (\class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    class_alias(PaymentHandlerModern::class, PaymentHandler::class);
} else {
    class_alias(PaymentHandlerLegacy::class, PaymentHandler::class);
}
