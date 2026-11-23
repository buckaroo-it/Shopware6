<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Merchant settings that decide whether a Buckaroo payment method may be used:
 * the per-method "Enabled" switch, PayPerEmail "Enabled frontend", Riverty customer
 * type "B2B" and the currency Twint (CHF) and Swish (SEK) require.
 *
 * Shared by the checkout page, the cart validator and the payment handlers, so the
 * same rules apply whichever way a payment is started.
 */
class PaymentMethodRuleService
{
    public const RESTRICTION_DISABLED = 'disabled';
    public const RESTRICTION_NOT_OFFERED_AT_CHECKOUT = 'not_offered_at_checkout';
    public const RESTRICTION_CUSTOMER_TYPE = 'customer_type';
    public const RESTRICTION_CURRENCY = 'currency';

    /**
     * @var array<string, string> Buckaroo key => ISO currency code the method requires
     */
    public const REQUIRED_CURRENCIES = [
        'twint' => 'CHF',
        'swish' => 'SEK',
    ];

    public function __construct(private readonly SettingsService $settingsService)
    {
    }

    /**
     * Restriction for paying the current cart, based on the sales channel context.
     */
    public function getCartRestriction(string $buckarooKey, SalesChannelContext $context): ?string
    {
        return $this->getRestriction(
            $buckarooKey,
            $context->getSalesChannelId(),
            $context->getCurrency()->getIsoCode(),
            $this->customerHasCompany($context->getCustomer()),
            $this->isShopperContext($context->getContext())
        );
    }

    /**
     * Restriction for paying an existing order, based on the stored order data.
     */
    public function getOrderRestriction(string $buckarooKey, OrderEntity $order, Context $context): ?string
    {
        return $this->getRestriction(
            $buckarooKey,
            $order->getSalesChannelId(),
            $order->getCurrency()?->getIsoCode(),
            $this->orderHasCompany($order),
            $this->isShopperContext($context)
        );
    }

    /**
     * Null when the payment method may be used, otherwise one of the RESTRICTION_* codes.
     *
     * @param bool $shopperContext false for orders created by the administration
     */
    public function getRestriction(
        string $buckarooKey,
        ?string $salesChannelId,
        ?string $currencyIsoCode,
        bool $hasCompany,
        bool $shopperContext = true
    ): ?string {
        if (!$this->settingsService->getEnabled($buckarooKey, $salesChannelId)) {
            return self::RESTRICTION_DISABLED;
        }

        if (
            $buckarooKey === 'payperemail'
            && $shopperContext
            && $this->settingsService->getSetting('payperemailEnabledfrontend', $salesChannelId) === false
        ) {
            return self::RESTRICTION_NOT_OFFERED_AT_CHECKOUT;
        }

        if ($buckarooKey === 'afterpay' && !$hasCompany && $this->isRivertyB2BOnly($salesChannelId)) {
            return self::RESTRICTION_CUSTOMER_TYPE;
        }

        $requiredCurrency = self::REQUIRED_CURRENCIES[$buckarooKey] ?? null;
        if ($requiredCurrency !== null && $currencyIsoCode !== $requiredCurrency) {
            return self::RESTRICTION_CURRENCY;
        }

        return null;
    }

    public function isRivertyB2BOnly(?string $salesChannelId): bool
    {
        return $this->settingsService->getSetting('afterpayCustomerType', $salesChannelId)
            === AfterPayPaymentHandler::CUSTOMER_TYPE_B2B;
    }

    /**
     * Company name on the billing or shipping address the cart is ordered with.
     */
    public function customerHasCompany(?CustomerEntity $customer): bool
    {
        if ($customer === null) {
            return false;
        }

        $billingAddress = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
        $shippingAddress = $customer->getActiveShippingAddress() ?? $customer->getDefaultShippingAddress();

        return self::isFilled($billingAddress?->getCompany()) || self::isFilled($shippingAddress?->getCompany());
    }

    /**
     * Company name on the billing or shipping address of the order.
     */
    public function orderHasCompany(OrderEntity $order): bool
    {
        if (self::isFilled($order->getBillingAddress()?->getCompany())) {
            return true;
        }

        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();

        return self::isFilled($shippingAddress?->getCompany());
    }

    /**
     * Orders the administration creates for a customer are not checkout payments.
     */
    private function isShopperContext(Context $context): bool
    {
        return !$context->getSource() instanceof AdminSalesChannelApiSource;
    }

    private static function isFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
