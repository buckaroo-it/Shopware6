<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\PaymentMethodRuleService;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPage;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The checkout confirm and "change payment method" pages list Buckaroo methods by the
 * same rules (PaymentMethodRuleService) the cart validator and payment handlers enforce.
 */
class CheckoutConfirmTemplateSubscriberMethodRulesTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ALL_ENABLED = [
        'idealEnabled' => true,
        'twintEnabled' => true,
        'swishEnabled' => true,
        'afterpayEnabled' => true,
        'payperemailEnabled' => true,
        'payperemailEnabledfrontend' => true,
    ];

    public function testEveryAllowedMethodIsListed(): void
    {
        static::assertSame(
            ['ideal', 'afterpay', 'payperemail', 'twint', null],
            $this->confirmPageKeys(self::ALL_ENABLED, 'CHF', null)
        );
    }

    public function testDisabledMethodIsHidden(): void
    {
        static::assertSame(
            ['afterpay', 'payperemail', 'swish', null],
            $this->confirmPageKeys(['idealEnabled' => false] + self::ALL_ENABLED, 'SEK', null)
        );
    }

    public function testCurrencyBoundMethodsAreHiddenInOtherCurrencies(): void
    {
        static::assertSame(
            ['ideal', 'afterpay', 'payperemail', null],
            $this->confirmPageKeys(self::ALL_ENABLED, 'EUR', null)
        );
    }

    public function testPayPerEmailNotOfferedAtCheckoutIsHidden(): void
    {
        static::assertSame(
            ['ideal', 'afterpay', null],
            $this->confirmPageKeys(['payperemailEnabledfrontend' => false] + self::ALL_ENABLED, 'EUR', null)
        );
    }

    public function testRivertyB2BOnlyIsHiddenWithoutCompany(): void
    {
        $settings = ['afterpayCustomerType' => 'b2b'] + self::ALL_ENABLED;

        static::assertSame(['ideal', 'payperemail', null], $this->confirmPageKeys($settings, 'EUR', null));
        static::assertSame(
            ['ideal', 'afterpay', 'payperemail', null],
            $this->confirmPageKeys($settings, 'EUR', 'Acme B.V.')
        );
    }

    public function testEditOrderPageUsesTheOrderCurrencyAndAddress(): void
    {
        $subscriber = $this->subscriber(['afterpayCustomerType' => 'b2b'] + self::ALL_ENABLED);

        $currency = new CurrencyEntity();
        $currency->setId('order-currency');
        $currency->setIsoCode('CHF');

        $billingAddress = new OrderAddressEntity();
        $billingAddress->setId('billing');
        $billingAddress->setCompany('Acme B.V.');

        $order = new OrderEntity();
        $order->setId('order');
        $order->setCurrency($currency);
        $order->setBillingAddress($billingAddress);
        $order->setAmountTotal(100.0);
        $order->setCustomFields([]);

        $page = new AccountEditOrderPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setOrder($order);

        $subscriber->hideNotEnabledPaymentMethods(
            new AccountEditOrderPageLoadedEvent($page, $this->context('EUR', null), new Request())
        );

        static::assertSame(
            ['ideal', 'afterpay', 'payperemail', 'twint', null],
            $this->keys($page->getPaymentMethods())
        );
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<string|null>
     */
    private function confirmPageKeys(array $settings, string $currencyIso, ?string $company): array
    {
        $page = new CheckoutConfirmPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setCart(new Cart('token'));

        $this->subscriber($settings)->hideNotEnabledPaymentMethods(
            new CheckoutConfirmPageLoadedEvent($page, $this->context($currencyIso, $company), new Request())
        );

        return $this->keys($page->getPaymentMethods());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function subscriber(array $settings): CheckoutConfirmTemplateSubscriber
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $settings[substr($key, \strlen('BuckarooPayments.config.'))] ?? null
        );
        $settingsService = new SettingsService($systemConfig);

        return new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->createMock(PayPalExpressCredentialsService::class),
            null,
            new PaymentMethodRuleService($settingsService)
        );
    }

    private function paymentMethods(): PaymentMethodCollection
    {
        $collection = new PaymentMethodCollection();
        foreach (['ideal', 'afterpay', 'payperemail', 'twint', 'swish', null] as $key) {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId(md5((string) $key));
            $paymentMethod->setHandlerIdentifier('handler-' . $key);
            $paymentMethod->setTranslated(['customFields' => $key === null ? [] : ['buckaroo_key' => $key]]);
            $collection->add($paymentMethod);
        }

        return $collection;
    }

    /**
     * @return list<string|null>
     */
    private function keys(PaymentMethodCollection $paymentMethods): array
    {
        return array_values($paymentMethods->map(
            static fn (PaymentMethodEntity $paymentMethod) =>
                $paymentMethod->getTranslated()['customFields']['buckaroo_key'] ?? null
        ));
    }

    private function context(string $currencyIso, ?string $company): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $address = new CustomerAddressEntity();
        $address->setId('address');
        if ($company !== null) {
            $address->setCompany($company);
        }

        $customer = new CustomerEntity();
        $customer->setId('customer');
        $customer->setDefaultBillingAddress($address);
        $customer->setDefaultShippingAddress($address);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID)));

        return $context;
    }
}
