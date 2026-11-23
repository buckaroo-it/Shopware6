<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\KlarnaPaymentHandler;
use Buckaroo\Shopware6\Handlers\KlarnainPaymentHandler;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentAmountLimitServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: float, 2: bool}>
     */
    public static function amountProvider(): iterable
    {
        // minimum only
        yield 'minimum: below' => [['afterpayMinOrderAmount' => 50.0], 49.99, false];
        yield 'minimum: at boundary' => [['afterpayMinOrderAmount' => 50.0], 50.00, true];
        yield 'minimum: above' => [['afterpayMinOrderAmount' => 50.0], 50.01, true];

        // maximum only
        yield 'maximum: below' => [['afterpayMaxOrderAmount' => 500.0], 499.99, true];
        yield 'maximum: at boundary' => [['afterpayMaxOrderAmount' => 500.0], 500.00, true];
        yield 'maximum: above' => [['afterpayMaxOrderAmount' => 500.0], 500.01, false];

        // both limits
        $both = ['afterpayMinOrderAmount' => 50.0, 'afterpayMaxOrderAmount' => 500.0];
        yield 'range: below minimum' => [$both, 49.99, false];
        yield 'range: at minimum' => [$both, 50.00, true];
        yield 'range: inside' => [$both, 250.00, true];
        yield 'range: at maximum' => [$both, 500.00, true];
        yield 'range: above maximum' => [$both, 500.01, false];

        // no limits
        yield 'no limits: empty settings' => [[], 0.01, true];
        $empty = ['afterpayMinOrderAmount' => '', 'afterpayMaxOrderAmount' => ''];
        yield 'no limits: empty strings' => [$empty, 99999.0, true];

        // precision: sums that are not exact in binary floating point
        yield 'precision: 0.1 + 0.2 at maximum 0.30' => [['afterpayMaxOrderAmount' => 0.3], 0.1 + 0.2, true];
        yield 'precision: 49.99 + 0.01 at minimum 50' => [['afterpayMinOrderAmount' => 50.0], 49.99 + 0.01, true];

        // stored as text (e.g. through the API) with a decimal comma
        yield 'text value with decimal comma' => [['afterpayMaxOrderAmount' => '500,50'], 500.50, true];
        yield 'text value with decimal comma, above' => [['afterpayMaxOrderAmount' => '500,50'], 500.51, false];
    }

    /**
     * @dataProvider amountProvider
     *
     * @param array<string, mixed> $settings
     */
    public function testIsAmountAllowed(array $settings, float $amount, bool $expected): void
    {
        $service = $this->createService($settings);

        static::assertSame($expected, $service->isAmountAllowed('afterpay', $amount, self::SALES_CHANNEL_ID));
    }

    public function testLimitsArePerPaymentMethod(): void
    {
        $service = $this->createService([
            'afterpayMaxOrderAmount' => 800.0,
            'klarnaMaxOrderAmount' => 1000.0,
        ]);

        static::assertFalse($service->isAmountAllowed('afterpay', 900.0, self::SALES_CHANNEL_ID));
        static::assertTrue($service->isAmountAllowed('klarna', 900.0, self::SALES_CHANNEL_ID));
        static::assertTrue($service->isAmountAllowed('ideal', 900.0, self::SALES_CHANNEL_ID));
    }

    public function testLimitsAreReadForTheGivenSalesChannel(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId) => match ([$key, $salesChannelId]) {
                ['afterpayMaxOrderAmount', 'channel-a'] => 800.0,
                ['afterpayMaxOrderAmount', 'channel-b'] => 600.0,
                default => null,
            }
        );
        $service = new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class));

        static::assertTrue($service->isAmountAllowed('afterpay', 700.0, 'channel-a'));
        static::assertFalse($service->isAmountAllowed('afterpay', 700.0, 'channel-b'));
    }

    public function testKlarnaInstallmentsUsesTheKlarnaLimits(): void
    {
        $service = $this->createService(['klarnaMaxOrderAmount' => 100.0]);

        static::assertFalse($service->isAmountAllowed('klarnain', 100.01, self::SALES_CHANNEL_ID));
        static::assertTrue($service->hasLimits('klarnain', self::SALES_CHANNEL_ID));
    }

    public function testComparesWithTheCurrencyDecimals(): void
    {
        $service = $this->createService(['afterpayMaxOrderAmount' => 1000.0]);

        // a currency without decimals rounds the amount to whole units
        static::assertTrue($service->isAmountAllowed('afterpay', 1000.4, self::SALES_CHANNEL_ID, 0));
        static::assertFalse($service->isAmountAllowed('afterpay', 1000.5, self::SALES_CHANNEL_ID, 0));
    }

    public function testRangeWithMinimumAboveMaximumIsAppliedAsConfiguredAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('warning');

        $service = $this->createService(
            ['afterpayMinOrderAmount' => 500.0, 'afterpayMaxOrderAmount' => 50.0],
            $logger
        );

        static::assertFalse($service->isAmountAllowed('afterpay', 100.0, self::SALES_CHANNEL_ID));
        static::assertFalse($service->isAmountAllowed('afterpay', 600.0, self::SALES_CHANNEL_ID));
    }

    public function testNonNumericSettingIsNoLimit(): void
    {
        $service = $this->createService(['afterpayMaxOrderAmount' => 'abc']);

        static::assertFalse($service->hasLimits('afterpay', self::SALES_CHANNEL_ID));
        static::assertTrue($service->isAmountAllowed('afterpay', 1000.0, self::SALES_CHANNEL_ID));
    }

    public function testGetBuckarooKeyUsesThePaymentHandler(): void
    {
        $service = $this->createService([]);

        static::assertSame('afterpay', $service->getBuckarooKey($this->paymentMethod(AfterPayPaymentHandler::class)));
        static::assertSame('ideal', $service->getBuckarooKey($this->paymentMethod(IdealPaymentHandler::class)));
        static::assertSame('klarna', $service->getBuckarooKey($this->paymentMethod(KlarnaPaymentHandler::class)));
        static::assertSame('klarnain', $service->getBuckarooKey($this->paymentMethod(KlarnainPaymentHandler::class)));
        static::assertNull($service->getBuckarooKey($this->paymentMethod('Some\\Other\\PaymentHandler')));
    }

    public function testCartTotalIncludingShippingIsChecked(): void
    {
        $service = $this->createService(['afterpayMaxOrderAmount' => 100.0]);
        $riverty = $this->paymentMethod(AfterPayPaymentHandler::class);
        $context = $this->salesChannelContext();

        // products 90 + shipping 10
        static::assertTrue($service->isAllowedForCart($riverty, $this->cart(90.0, 100.0), $context));
        // products 90 + shipping 11
        static::assertFalse($service->isAllowedForCart($riverty, $this->cart(90.0, 101.0), $context));
    }

    public function testOtherPaymentProvidersAreAlwaysAllowed(): void
    {
        $service = $this->createService(['afterpayMaxOrderAmount' => 1.0]);

        static::assertTrue($service->isAllowedForCart(
            $this->paymentMethod('Some\\Other\\PaymentHandler'),
            $this->cart(1000.0, 1000.0),
            $this->salesChannelContext()
        ));
    }

    public function testOrderTotalWithoutEarlierBuckarooFeeIsChecked(): void
    {
        $service = $this->createService(['afterpayMaxOrderAmount' => 800.0]);
        $riverty = $this->paymentMethod(AfterPayPaymentHandler::class);

        static::assertFalse($service->isAllowedForOrder($riverty, $this->order(1800.0)));
        static::assertTrue($service->isAllowedForOrder($riverty, $this->order(800.0)));
        // 802.50 includes a fee of 2.50 from an earlier payment attempt
        static::assertTrue($service->isAllowedForOrder($riverty, $this->order(802.5, ['buckarooFee' => 2.5])));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createService(array $settings, ?LoggerInterface $logger = null): PaymentAmountLimitService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        return new PaymentAmountLimitService($settingsService, $logger ?? $this->createMock(LoggerInterface::class));
    }

    private function paymentMethod(string $handler): PaymentMethodEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(md5($handler));
        $paymentMethod->setHandlerIdentifier($handler);

        return $paymentMethod;
    }

    private function cart(float $positionPrice, float $totalPrice): Cart
    {
        $cart = new Cart('token');
        $cart->setPrice(new CartPrice(
            $totalPrice,
            $totalPrice,
            $positionPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $cart;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getTotalRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));

        return $context;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function order(float $amountTotal, array $customFields = []): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(md5((string) $amountTotal));
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setAmountTotal($amountTotal);
        $order->setTotalRounding(new CashRoundingConfig(2, 0.01, true));
        $order->setCustomFields($customFields);
        $order->setPrice(new CartPrice(
            $amountTotal,
            $amountTotal,
            $amountTotal,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $order;
    }
}
