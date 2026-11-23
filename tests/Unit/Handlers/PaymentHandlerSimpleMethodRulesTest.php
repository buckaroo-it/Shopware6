<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\SwishPaymentHandler;
use Buckaroo\Shopware6\Handlers\TwintPaymentHandler;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\PaymentMethodRuleService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shopware 6.6.10+ / 6.7 handler line: validate() stops an order with a payment method
 * the merchant settings do not allow, and pay() refuses it as well, because paying an
 * existing order (handle-payment, changed payment method) does not call validate().
 * A refused payment never asks the client service for a Buckaroo client.
 */
class PaymentHandlerSimpleMethodRulesTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        if (!class_exists(AbstractPaymentHandler::class)) {
            $this->markTestSkipped('This branch of PaymentHandlerSimple requires AbstractPaymentHandler (6.6.10+)');
        }
    }

    public function testValidateRefusesDisabledMethod(): void
    {
        $handler = new IdealPaymentHandler($this->asyncPaymentService(['idealEnabled' => false]));

        $this->expectException(PaymentException::class);

        $handler->validate(new Cart('token'), new RequestDataBag(), $this->salesChannelContext('EUR'));
    }

    public function testValidateAcceptsEnabledMethod(): void
    {
        $handler = new IdealPaymentHandler($this->asyncPaymentService(['idealEnabled' => true]));

        static::assertNull(
            $handler->validate(new Cart('token'), new RequestDataBag(), $this->salesChannelContext('EUR'))
        );
    }

    public function testValidateRefusesTwintOutsideSwissFrancs(): void
    {
        $handler = new TwintPaymentHandler($this->asyncPaymentService(['twintEnabled' => true]));

        static::assertNull(
            $handler->validate(new Cart('token'), new RequestDataBag(), $this->salesChannelContext('CHF'))
        );

        $this->expectException(PaymentException::class);
        $handler->validate(new Cart('token'), new RequestDataBag(), $this->salesChannelContext('EUR'));
    }

    public function testPayRefusesDisabledMethodBeforeAnyBuckarooRequest(): void
    {
        $handler = new IdealPaymentHandler($this->asyncPaymentService(['idealEnabled' => false], 'EUR', false));

        $this->expectExceptionObject(
            PaymentException::asyncProcessInterrupted('transaction-id', 'Payment method not available')
        );

        $this->pay($handler);
    }

    public function testPayRefusesSwishOutsideSwedishKronaBeforeAnyBuckarooRequest(): void
    {
        $handler = new SwishPaymentHandler($this->asyncPaymentService(['swishEnabled' => true], 'EUR', false));

        $this->expectExceptionObject(
            PaymentException::asyncProcessInterrupted('transaction-id', 'Payment method not available')
        );

        $this->pay($handler);
    }

    /**
     * An allowed method passes the check; pay() is then halted at the fee calculation.
     */
    public function testPayContinuesForAllowedMethod(): void
    {
        $handler = new SwishPaymentHandler($this->asyncPaymentService(['swishEnabled' => true], 'SEK'));

        try {
            $this->pay($handler);
            static::fail('pay() should have been halted at the fee calculation');
        } catch (PaymentException $exception) {
            static::assertStringContainsString('halt after the method check', $exception->getMessage());
        }
    }

    private function pay(AbstractPaymentHandler $handler): void
    {
        $handler->pay(
            new Request(),
            new PaymentTransactionStruct('transaction-id', 'https://shop.test/return'),
            new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID))
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function asyncPaymentService(
        array $settings,
        string $orderCurrency = 'EUR',
        bool $clientAllowed = true
    ): AsyncPaymentService {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $settings[substr($key, \strlen('BuckarooPayments.config.'))] ?? null
        );
        $realSettings = new SettingsService($systemConfig);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );
        $settingsService->method('calculateBuckarooFee')
            ->willThrowException(new \RuntimeException('halt after the method check'));

        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($orderCurrency);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setAmountTotal(100.0);
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setOrder($order);

        $clientService = $this->createMock(ClientService::class);
        if (!$clientAllowed) {
            $clientService->expects($this->never())->method('get');
        }

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->clientService = $clientService;
        $asyncPaymentService->logger = $this->createMock(LoggerInterface::class);
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);
        $asyncPaymentService->method('getTransaction')->willReturn($orderTransaction);
        $asyncPaymentService->method('getSalesChannelContext')
            ->willReturn($this->createMock(SalesChannelContext::class));
        $asyncPaymentService->method('getPaymentMethodRuleService')
            ->willReturn(new PaymentMethodRuleService($realSettings));

        return $asyncPaymentService;
    }

    private function salesChannelContext(string $currencyIso): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getContext')->willReturn(new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID)));

        return $context;
    }
}
