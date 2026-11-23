<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\PaymentMethodRuleService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The merchant settings that decide whether a Buckaroo payment method may be used,
 * read with the real SettingsService so "Enabled" keeps its existing meaning.
 */
class PaymentMethodRuleServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testEnabledMethodIsAllowed(): void
    {
        $service = $this->service(['idealEnabled' => true]);

        static::assertNull($service->getRestriction('ideal', self::SALES_CHANNEL_ID, 'EUR', false));
    }

    public function testDisabledMethodIsRestricted(): void
    {
        $service = $this->service(['idealEnabled' => false]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_DISABLED,
            $service->getRestriction('ideal', self::SALES_CHANNEL_ID, 'EUR', false)
        );
    }

    public function testMethodWithoutEnabledValueIsRestrictedLikeTheCheckoutPage(): void
    {
        $service = $this->service([]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_DISABLED,
            $service->getRestriction('ideal', self::SALES_CHANNEL_ID, 'EUR', false)
        );
    }

    public function testPayPerEmailNotOfferedAtCheckoutIsRestrictedForShoppers(): void
    {
        $service = $this->service(['payperemailEnabled' => true, 'payperemailEnabledfrontend' => false]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_NOT_OFFERED_AT_CHECKOUT,
            $service->getRestriction('payperemail', self::SALES_CHANNEL_ID, 'EUR', false)
        );
    }

    public function testPayPerEmailNotOfferedAtCheckoutStaysAvailableForAdministrationOrders(): void
    {
        $service = $this->service(['payperemailEnabled' => true, 'payperemailEnabledfrontend' => false]);

        static::assertNull(
            $service->getRestriction('payperemail', self::SALES_CHANNEL_ID, 'EUR', false, false)
        );
        static::assertNull($service->getOrderRestriction(
            'payperemail',
            $this->order('EUR'),
            new Context(new AdminSalesChannelApiSource(self::SALES_CHANNEL_ID, new Context(new SystemSource())))
        ));
    }

    public function testPayPerEmailOfferedAtCheckoutIsAllowed(): void
    {
        $service = $this->service(['payperemailEnabled' => true, 'payperemailEnabledfrontend' => true]);

        static::assertNull($service->getRestriction('payperemail', self::SALES_CHANNEL_ID, 'EUR', false));
    }

    public function testPayPerEmailDisabledIsRestrictedEvenForAdministrationOrders(): void
    {
        $service = $this->service(['payperemailEnabled' => false, 'payperemailEnabledfrontend' => true]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_DISABLED,
            $service->getRestriction('payperemail', self::SALES_CHANNEL_ID, 'EUR', false, false)
        );
    }

    public function testRivertyB2BOnlyRestrictsConsumers(): void
    {
        $service = $this->service(['afterpayEnabled' => true, 'afterpayCustomerType' => 'b2b']);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CUSTOMER_TYPE,
            $service->getRestriction('afterpay', self::SALES_CHANNEL_ID, 'EUR', false)
        );
        static::assertNull($service->getRestriction('afterpay', self::SALES_CHANNEL_ID, 'EUR', true));
    }

    public function testRivertyForConsumersOrBothAllowsConsumers(): void
    {
        foreach (['b2c', 'both', null] as $customerType) {
            $service = $this->service(['afterpayEnabled' => true, 'afterpayCustomerType' => $customerType]);

            static::assertNull($service->getRestriction('afterpay', self::SALES_CHANNEL_ID, 'EUR', false));
        }
    }

    public function testTwintRequiresSwissFrancs(): void
    {
        $service = $this->service(['twintEnabled' => true]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CURRENCY,
            $service->getRestriction('twint', self::SALES_CHANNEL_ID, 'EUR', false)
        );
        static::assertNull($service->getRestriction('twint', self::SALES_CHANNEL_ID, 'CHF', false));
    }

    public function testSwishRequiresSwedishKrona(): void
    {
        $service = $this->service(['swishEnabled' => true]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CURRENCY,
            $service->getRestriction('swish', self::SALES_CHANNEL_ID, 'EUR', false)
        );
        static::assertNull($service->getRestriction('swish', self::SALES_CHANNEL_ID, 'SEK', false));
    }

    public function testUnknownCurrencyIsRestrictedForCurrencyBoundMethods(): void
    {
        $service = $this->service(['twintEnabled' => true]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CURRENCY,
            $service->getRestriction('twint', self::SALES_CHANNEL_ID, null, false)
        );
    }

    public function testUnrestrictedMethodIgnoresCurrencyAndCustomerType(): void
    {
        $service = $this->service(['idealEnabled' => true, 'afterpayCustomerType' => 'b2b']);

        static::assertNull($service->getRestriction('ideal', self::SALES_CHANNEL_ID, 'SEK', false));
    }

    public function testOrderRestrictionUsesTheOrderCurrencyAndAddresses(): void
    {
        $service = $this->service([
            'twintEnabled' => true,
            'afterpayEnabled' => true,
            'afterpayCustomerType' => 'b2b',
        ]);
        $shopper = new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID));

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CURRENCY,
            $service->getOrderRestriction('twint', $this->order('EUR'), $shopper)
        );
        static::assertNull($service->getOrderRestriction('twint', $this->order('CHF'), $shopper));

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CUSTOMER_TYPE,
            $service->getOrderRestriction('afterpay', $this->order('EUR'), $shopper)
        );
        static::assertNull(
            $service->getOrderRestriction('afterpay', $this->order('EUR', 'Acme B.V.'), $shopper)
        );
        static::assertNull(
            $service->getOrderRestriction('afterpay', $this->order('EUR', null, 'Acme B.V.'), $shopper)
        );
        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CUSTOMER_TYPE,
            $service->getOrderRestriction('afterpay', $this->order('EUR', '   '), $shopper)
        );
    }

    public function testCartRestrictionUsesTheContextCurrencyAndCustomerAddresses(): void
    {
        $service = $this->service([
            'swishEnabled' => true,
            'afterpayEnabled' => true,
            'afterpayCustomerType' => 'b2b',
        ]);

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CURRENCY,
            $service->getCartRestriction('swish', $this->salesChannelContext('EUR', null))
        );
        static::assertNull($service->getCartRestriction('swish', $this->salesChannelContext('SEK', null)));

        static::assertSame(
            PaymentMethodRuleService::RESTRICTION_CUSTOMER_TYPE,
            $service->getCartRestriction('afterpay', $this->salesChannelContext('EUR', $this->customer(null)))
        );
        static::assertNull(
            $service->getCartRestriction('afterpay', $this->salesChannelContext('EUR', $this->customer('Acme B.V.')))
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function service(array $settings): PaymentMethodRuleService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $settings[substr($key, \strlen('BuckarooPayments.config.'))] ?? null
        );

        return new PaymentMethodRuleService(new SettingsService($systemConfig));
    }

    private function order(
        string $currencyIso,
        ?string $billingCompany = null,
        ?string $shippingCompany = null
    ): OrderEntity {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $billingAddress = new OrderAddressEntity();
        $billingAddress->setId('billing');
        $billingAddress->setCompany($billingCompany);

        $shippingAddress = new OrderAddressEntity();
        $shippingAddress->setId('shipping');
        $shippingAddress->setCompany($shippingCompany);

        $delivery = new OrderDeliveryEntity();
        $delivery->setId('delivery');
        $delivery->setShippingOrderAddress($shippingAddress);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setCurrency($currency);
        $order->setBillingAddress($billingAddress);
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));

        return $order;
    }

    private function customer(?string $company): CustomerEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId('address');
        if ($company !== null) {
            $address->setCompany($company);
        }

        $customer = new CustomerEntity();
        $customer->setId('customer');
        $customer->setDefaultBillingAddress($address);
        $customer->setDefaultShippingAddress($address);

        return $customer;
    }

    private function salesChannelContext(string $currencyIso, ?CustomerEntity $customer): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID)));

        return $context;
    }
}
