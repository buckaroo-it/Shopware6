<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Blocks the order when the selected Buckaroo payment method does not allow the cart
 * total (minimum / maximum order amount), like Shopware blocks a payment method
 * whose availability rule does not match. Shopware's storefront then switches to
 * another available payment method, and placing the order is refused while the
 * error is present (storefront, Store API and the express checkout flows).
 */
class PaymentAmountLimitCartValidator implements CartValidatorInterface
{
    public const REASON = 'order amount outside the payment method limits';

    public function __construct(private readonly PaymentAmountLimitService $limitService)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        $paymentMethod = $context->getPaymentMethod();

        if ($this->limitService->isAllowedForCart($paymentMethod, $cart, $context)) {
            return;
        }

        $name = $paymentMethod->getTranslation('name');

        $errors->add(new PaymentMethodBlockedError(
            \is_string($name) ? $name : '',
            self::REASON,
            $paymentMethod->getId()
        ));
    }
}
