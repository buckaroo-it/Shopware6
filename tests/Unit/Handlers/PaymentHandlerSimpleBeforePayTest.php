<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Handlers\AfterPayOld;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Riverty (AfterPay) flags the order as authorized in its beforePayModern() hook, and
 * capture-on-shipment only captures orders carrying that flag. PaymentHandlerSimple
 * used to skip the hook, so with "Enable Authorize-Capture flow" on, a shipped Riverty
 * order was never captured.
 *
 * pay() is halted right after the hook (the fee calculation throws) so no Buckaroo
 * request is built. Only runs on the Shopware line that ships AbstractPaymentHandler.
 */
class PaymentHandlerSimpleBeforePayTest extends TestCase
{
    private const ORDER_ID = '0189f0e0e0e0a1b2c3d4e5f6a7b8c9d0';

    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    protected function setUp(): void
    {
        if (!class_exists(AbstractPaymentHandler::class)) {
            $this->markTestSkipped('This pay() branch of PaymentHandlerSimple requires Shopware >= 6.7');
        }
    }

    public function testRivertyOrderIsFlaggedAsAuthorizedWhenAuthorizeCaptureIsEnabled(): void
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->once())
            ->method('appendCustomFields')
            ->with(self::ORDER_ID, [CaptureService::ORDER_IS_AUTHORIZED => true], $this->isInstanceOf(Context::class));

        $this->pay($checkoutHelper, true);
    }

    public function testRivertyOrderIsNotFlaggedWhenAuthorizeCaptureIsDisabled(): void
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->never())->method('appendCustomFields');

        $this->pay($checkoutHelper, false);
    }

    private function pay(CheckoutHelper $checkoutHelper, bool $authorizeEnabled): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setAmountTotal(100.0);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setOrder($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->willReturnCallback(
                fn (string $key) => $key === 'afterpayAuthorize' ? $authorizeEnabled : null
            );
        $settingsService->method('calculateBuckarooFee')
            ->willThrowException(new \RuntimeException('halt after the pre-pay hook'));

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->checkoutHelper = $checkoutHelper;
        $asyncPaymentService->logger = $this->createMock(LoggerInterface::class);
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);
        $asyncPaymentService->method('getTransaction')->willReturn($orderTransaction);
        $asyncPaymentService->method('getSalesChannelContext')
            ->willReturn($this->createMock(SalesChannelContext::class));

        $handler = new AfterPayPaymentHandler($asyncPaymentService, $this->createMock(AfterPayOld::class));

        $this->expectException(PaymentException::class);

        $handler->pay(
            new Request(),
            new PaymentTransactionStruct('transaction-id', 'https://shop.test/return'),
            Context::createDefaultContext()
        );
    }
}
