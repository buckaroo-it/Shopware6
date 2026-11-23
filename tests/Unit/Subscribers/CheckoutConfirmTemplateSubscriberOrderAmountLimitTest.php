<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPage;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Storefront checkout confirm page (cart total) and "change payment method" page of
 * an existing order (order total) only list Buckaroo methods within their limits.
 */
class CheckoutConfirmTemplateSubscriberOrderAmountLimitTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testConfirmPageUsesTheCartTotal(): void
    {
        $subscriber = $this->createSubscriber(['klarnaMaxOrderAmount' => 800.0]);

        $page = new CheckoutConfirmPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setCart($this->cart(800.01));

        $subscriber->hideNotEnabledPaymentMethods(
            new CheckoutConfirmPageLoadedEvent($page, $this->context(), new Request())
        );

        static::assertSame(['ideal', null], $this->keys($page->getPaymentMethods()));
    }

    public function testConfirmPageKeepsMethodAtTheBoundary(): void
    {
        $subscriber = $this->createSubscriber(['klarnaMaxOrderAmount' => 800.0]);

        $page = new CheckoutConfirmPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setCart($this->cart(800.00));

        $subscriber->hideNotEnabledPaymentMethods(
            new CheckoutConfirmPageLoadedEvent($page, $this->context(), new Request())
        );

        static::assertSame(['klarna', 'ideal', null], $this->keys($page->getPaymentMethods()));
    }

    public function testEditOrderPageUsesTheOrderTotal(): void
    {
        $subscriber = $this->createSubscriber(['klarnaMaxOrderAmount' => 800.0]);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setAmountTotal(1800.0);
        $order->setTotalRounding(new CashRoundingConfig(2, 0.01, true));
        $order->setCustomFields([]);

        $page = new AccountEditOrderPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setOrder($order);

        $subscriber->hideNotEnabledPaymentMethods(
            new AccountEditOrderPageLoadedEvent($page, $this->context(), new Request())
        );

        static::assertSame(['ideal', null], $this->keys($page->getPaymentMethods()));
    }

    public function testNoLimitsKeepsEveryMethod(): void
    {
        $subscriber = $this->createSubscriber([]);

        $page = new CheckoutConfirmPage();
        $page->setPaymentMethods($this->paymentMethods());
        $page->setCart($this->cart(100000.0));

        $subscriber->hideNotEnabledPaymentMethods(
            new CheckoutConfirmPageLoadedEvent($page, $this->context(), new Request())
        );

        static::assertSame(['klarna', 'ideal', null], $this->keys($page->getPaymentMethods()));
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function createSubscriber(array $limits): CheckoutConfirmTemplateSubscriber
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getEnabled')->willReturn(true);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $limits[$key] ?? null
        );

        return new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->createMock(PayPalExpressCredentialsService::class),
            new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class))
        );
    }

    private function paymentMethods(): PaymentMethodCollection
    {
        $collection = new PaymentMethodCollection();
        foreach (['klarna', 'ideal', null] as $key) {
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

    private function cart(float $totalPrice): Cart
    {
        $cart = new Cart('token');
        $cart->setPrice(new CartPrice(
            $totalPrice,
            $totalPrice,
            $totalPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $cart;
    }

    private function context(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getTotalRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));

        return $context;
    }
}
