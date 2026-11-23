<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\SwishPaymentHandler;
use Buckaroo\Shopware6\Handlers\TwintPaymentHandler;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\PaymentMethodRuleCartValidator;
use Buckaroo\Shopware6\Service\PaymentMethodRuleService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The cart validator runs whenever a cart is calculated, so an order with a Buckaroo
 * payment method the merchant settings do not allow cannot be placed, whether it comes
 * from the storefront, the Store API or an express checkout.
 */
class PaymentMethodRuleCartValidatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testDisabledMethodBlocksTheOrder(): void
    {
        $errors = $this->validate(['idealEnabled' => false], IdealPaymentHandler::class, 'EUR');

        static::assertCount(1, $errors);
        static::assertInstanceOf(PaymentMethodBlockedError::class, $errors->first());
        static::assertTrue($errors->blockOrder());
    }

    public function testEnabledMethodKeepsTheCartValid(): void
    {
        static::assertCount(0, $this->validate(['idealEnabled' => true], IdealPaymentHandler::class, 'EUR'));
    }

    public function testTwintInAnotherCurrencyBlocksTheOrder(): void
    {
        static::assertTrue($this->validate(['twintEnabled' => true], TwintPaymentHandler::class, 'EUR')->blockOrder());
        static::assertCount(0, $this->validate(['twintEnabled' => true], TwintPaymentHandler::class, 'CHF'));
    }

    public function testSwishInAnotherCurrencyBlocksTheOrder(): void
    {
        static::assertTrue($this->validate(['swishEnabled' => true], SwishPaymentHandler::class, 'EUR')->blockOrder());
        static::assertCount(0, $this->validate(['swishEnabled' => true], SwishPaymentHandler::class, 'SEK'));
    }

    public function testOtherPaymentProvidersAreNotChecked(): void
    {
        static::assertCount(0, $this->validate([], 'Some\\Other\\PaymentHandler', 'EUR'));
    }

    public function testEmptyCartIsNotChecked(): void
    {
        static::assertCount(0, $this->validate(['idealEnabled' => false], IdealPaymentHandler::class, 'EUR', false));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function validate(
        array $settings,
        string $handler,
        string $currencyIso,
        bool $withLineItem = true
    ): ErrorCollection {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $settings[substr($key, \strlen('BuckarooPayments.config.'))] ?? null
        );
        $settingsService = new SettingsService($systemConfig);

        $validator = new PaymentMethodRuleCartValidator(
            new PaymentMethodRuleService($settingsService),
            new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class))
        );

        $cart = new Cart('token');
        if ($withLineItem) {
            $cart->add(new LineItem('product', LineItem::PRODUCT_LINE_ITEM_TYPE));
        }

        $errors = new ErrorCollection();
        $validator->validate($cart, $errors, $this->context($handler, $currencyIso));

        return $errors;
    }

    private function context(string $handler, string $currencyIso): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(md5($handler));
        $paymentMethod->setHandlerIdentifier($handler);
        $paymentMethod->setTranslated(['name' => 'Buckaroo']);

        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getContext')->willReturn(new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID)));

        return $context;
    }
}
