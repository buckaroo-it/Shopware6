<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Handlers\AfterPayOld;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\PayPerEmailPaymentHandler;
use Buckaroo\Shopware6\Handlers\TwintPaymentHandler;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\Service\PaymentMethodRuleService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Shopware 6.5 (and 6.6 before AbstractPaymentHandler) handler line: pay() runs through
 * PaymentHandlerLegacy, which refuses a payment method the merchant settings do not
 * allow before anything is sent to Buckaroo. This covers payments of existing orders
 * (handle-payment, changed payment method) that never pass the cart validator.
 */
class PaymentHandlerLegacyMethodRulesTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        if (
            class_exists('Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')
            || !interface_exists(
                'Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface'
            )
        ) {
            $this->markTestSkipped('The legacy pay() path only runs on Shopware without AbstractPaymentHandler');
        }
    }

    public function testDisabledMethodIsRefused(): void
    {
        $service = $this->asyncPaymentService(['idealEnabled' => false], false);

        $this->expectPaymentMethodNotAvailable();
        $this->pay(new IdealPaymentHandler($service), $this->order('EUR'));
    }

    public function testTwintOutsideSwissFrancsIsRefused(): void
    {
        $service = $this->asyncPaymentService(['twintEnabled' => true], false);

        $this->expectPaymentMethodNotAvailable();
        $this->pay(new TwintPaymentHandler($service), $this->order('EUR'));
    }

    public function testPayPerEmailNotOfferedAtCheckoutIsRefused(): void
    {
        $service = $this->asyncPaymentService(
            ['payperemailEnabled' => true, 'payperemailEnabledfrontend' => false],
            false
        );

        $this->expectPaymentMethodNotAvailable();
        $this->pay(
            new PayPerEmailPaymentHandler($service, $this->createMock(PayLinkService::class)),
            $this->order('EUR')
        );
    }

    public function testRivertyB2BOnlyRefusesConsumerOrder(): void
    {
        $service = $this->asyncPaymentService(['afterpayEnabled' => true, 'afterpayCustomerType' => 'b2b'], false);

        $this->expectPaymentMethodNotAvailable();
        $this->pay(new AfterPayPaymentHandler($service, $this->createMock(AfterPayOld::class)), $this->order('EUR'));
    }

    /**
     * Allowed methods pass the check; pay() is then halted at the fee calculation.
     */
    public function testAllowedMethodsContinue(): void
    {
        $cases = [
            [new TwintPaymentHandler($this->asyncPaymentService(['twintEnabled' => true])), 'CHF', null],
            [new IdealPaymentHandler($this->asyncPaymentService(['idealEnabled' => true])), 'EUR', null],
            [
                new AfterPayPaymentHandler(
                    $this->asyncPaymentService(['afterpayEnabled' => true, 'afterpayCustomerType' => 'b2b']),
                    $this->createMock(AfterPayOld::class)
                ),
                'EUR',
                'Acme B.V.',
            ],
        ];

        foreach ($cases as [$handler, $currency, $company]) {
            try {
                $this->pay($handler, $this->order($currency, $company));
                static::fail('pay() should have been halted at the fee calculation');
            } catch (PaymentException $exception) {
                static::assertStringNotContainsString('Payment method not available', $exception->getMessage());
            }
        }
    }

    private function expectPaymentMethodNotAvailable(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment method not available');
    }

    private function pay(object $handler, OrderEntity $order): void
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setOrder($order);

        $structClass = 'Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct';

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getContext')
            ->willReturn(new Context(new SalesChannelApiSource(self::SALES_CHANNEL_ID)));

        $handler->pay(
            new $structClass($orderTransaction, $order, 'https://shop.test/return'),
            new RequestDataBag(),
            $salesChannelContext
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function asyncPaymentService(array $settings, bool $clientAllowed = true): AsyncPaymentService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $settings[substr($key, \strlen('BuckarooPayments.config.'))] ?? null
        );

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );
        $settingsService->method('calculateBuckarooFee')
            ->willThrowException(new \RuntimeException('halt after the method check'));

        $clientService = $this->createMock(ClientService::class);
        if (!$clientAllowed) {
            $clientService->expects($this->never())->method('get');
        }

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->clientService = $clientService;
        $asyncPaymentService->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $asyncPaymentService->logger = $this->createMock(LoggerInterface::class);
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);
        $asyncPaymentService->method('getPaymentMethodRuleService')
            ->willReturn(new PaymentMethodRuleService(new SettingsService($systemConfig)));

        return $asyncPaymentService;
    }

    private function order(string $currencyIso, ?string $billingCompany = null): OrderEntity
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency');
        $currency->setIsoCode($currencyIso);

        $billingAddress = new OrderAddressEntity();
        $billingAddress->setId('billing');
        $billingAddress->setCompany($billingCompany);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setAmountTotal(100.0);
        $order->setCurrency($currency);
        $order->setBillingAddress($billingAddress);
        $order->setCustomFields([]);

        return $order;
    }
}
