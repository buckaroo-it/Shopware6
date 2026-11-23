<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Blocks the order when the selected Buckaroo payment method is not available under
 * the merchant settings (PaymentMethodRuleService), like Shopware blocks a payment
 * method whose availability rule does not match. Applies to the storefront, the
 * Store API and the express checkout flows alike.
 */
class PaymentMethodRuleCartValidator implements CartValidatorInterface
{
    public const REASON = 'not available for this checkout';

    public function __construct(
        private readonly PaymentMethodRuleService $ruleService,
        private readonly PaymentAmountLimitService $limitService
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        $paymentMethod = $context->getPaymentMethod();

        $buckarooKey = $this->limitService->getBuckarooKey($paymentMethod);
        if ($buckarooKey === null || $this->ruleService->getCartRestriction($buckarooKey, $context) === null) {
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
