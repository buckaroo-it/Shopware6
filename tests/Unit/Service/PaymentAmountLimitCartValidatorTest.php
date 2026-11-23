<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Service\PaymentAmountLimitCartValidator;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The selected payment method is checked against the recalculated cart total every
 * time the cart is calculated, so a cart change (voucher, quantity, shipping) that
 * moves the total outside the limits blocks the order.
 */
class PaymentAmountLimitCartValidatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testVoucherBelowMinimumBlocksTheSelectedPaymentMethod(): void
    {
        $validator = $this->createValidator(['afterpayMinOrderAmount' => 50.0]);
        $context = $this->context(AfterPayPaymentHandler::class);

        // 60.00 before the voucher
        $errors = new ErrorCollection();
        $validator->validate($this->cart(60.0), $errors, $context);
        static::assertCount(0, $errors);

        // 40.00 after a voucher of 20.00
        $errors = new ErrorCollection();
        $validator->validate($this->cart(40.0), $errors, $context);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(PaymentMethodBlockedError::class, $error);
        static::assertTrue($error->blockOrder());
        static::assertTrue($errors->blockOrder());
    }

    public function testTotalAboveMaximumBlocksTheSelectedPaymentMethod(): void
    {
        $validator = $this->createValidator(['afterpayMaxOrderAmount' => 800.0]);
        $context = $this->context(AfterPayPaymentHandler::class);

        $errors = new ErrorCollection();
        $validator->validate($this->cart(800.0), $errors, $context);
        static::assertCount(0, $errors);

        $errors = new ErrorCollection();
        $validator->validate($this->cart(800.01), $errors, $context);
        static::assertTrue($errors->blockOrder());
    }

    public function testNoLimitsKeepsTheCartValid(): void
    {
        $validator = $this->createValidator([]);

        $errors = new ErrorCollection();
        $validator->validate($this->cart(100000.0), $errors, $this->context(AfterPayPaymentHandler::class));

        static::assertCount(0, $errors);
    }

    public function testOtherPaymentProvidersAreNotChecked(): void
    {
        $validator = $this->createValidator(['afterpayMaxOrderAmount' => 1.0]);

        $errors = new ErrorCollection();
        $validator->validate($this->cart(1000.0), $errors, $this->context('Some\\Other\\PaymentHandler'));

        static::assertCount(0, $errors);
    }

    public function testEmptyCartIsNotChecked(): void
    {
        $validator = $this->createValidator(['afterpayMinOrderAmount' => 50.0]);

        $errors = new ErrorCollection();
        $validator->validate($this->cart(0.0, false), $errors, $this->context(AfterPayPaymentHandler::class));

        static::assertCount(0, $errors);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createValidator(array $settings): PaymentAmountLimitCartValidator
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        return new PaymentAmountLimitCartValidator(
            new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class))
        );
    }

    private function cart(float $totalPrice, bool $withLineItem = true): Cart
    {
        $cart = new Cart('token');
        if ($withLineItem) {
            $cart->add(new LineItem('product', LineItem::PRODUCT_LINE_ITEM_TYPE));
        }
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

    private function context(string $handler): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(md5($handler));
        $paymentMethod->setHandlerIdentifier($handler);
        $paymentMethod->setTranslated(['name' => 'Riverty']);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getTotalRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));

        return $context;
    }
}
