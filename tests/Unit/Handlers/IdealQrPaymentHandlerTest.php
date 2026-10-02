<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderEntity;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * The iDEAL QR purchase id is the invoice of the iDEAL QR order. That order used to be
 * created in a hook neither pay() branch calls, so building the payload read an
 * uninitialised property. The QR amount must be the order total, which already holds
 * the Buckaroo fee when the payload is built.
 */
class IdealQrPaymentHandlerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    private const TRANSACTION_ID = 'transaction-id';

    protected function setUp(): void
    {
        if (!class_exists(AbstractPaymentHandler::class)) {
            $this->markTestSkipped('This pay() branch of PaymentHandlerSimple requires Shopware >= 6.7');
        }
    }

    public function testPayCreatesTheIdealQrOrderBeforeThePayloadIsBuilt(): void
    {
        $repository = $this->createMock(IdealQrOrderRepository::class);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(OrderTransactionEntity::class), $this->isInstanceOf(Context::class))
            ->willReturn($this->idealQrOrder(42));

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('calculateBuckarooFee')
            ->willThrowException(new \RuntimeException('halt after the pre-pay hook'));

        $handler = new IdealQrPaymentHandler($this->asyncPaymentService($settingsService), $repository);

        try {
            $handler->pay(
                new Request(),
                new PaymentTransactionStruct(self::TRANSACTION_ID, 'https://shop.test/return'),
                Context::createDefaultContext()
            );
            $this->fail('pay() was expected to halt');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('halt after the pre-pay hook', $exception->getMessage());
        }
    }

    public function testPayloadUsesTheInvoiceAndTheOrderTotalIncludingTheFee(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getBuckarooFee')->willReturn(2.5);
        $settingsService->method('calculateBuckarooFee')->willReturn(2.5);

        $repository = $this->createMock(IdealQrOrderRepository::class);
        $repository->method('create')->willReturn($this->idealQrOrder(42));

        $handler = new IdealQrPaymentHandler($this->asyncPaymentService($settingsService), $repository);
        $this->runPrePayHook($handler);

        // The fee was applied to the order before the payload is built
        $order = $this->order();
        $order->setAmountTotal(102.5);
        $order->setCustomFields(['buckarooFee' => 2.5]);

        $payload = $handler->getMethodPayload(
            $order,
            new RequestDataBag(),
            $this->salesChannelContext(),
            'idealqr'
        );

        $this->assertSame(IdealQrPaymentHandler::IDEAL_QR_INVOICE_PREFIX . '42', $payload['purchaseId']);
        $this->assertSame(102.5, $payload['amount']);
    }

    public function testPayloadIsNotBuiltWithoutAnIdealQrOrder(): void
    {
        $repository = $this->createMock(IdealQrOrderRepository::class);
        $repository->method('create')->willReturnOnConsecutiveCalls($this->idealQrOrder(42), null);

        $handler = new IdealQrPaymentHandler(
            $this->asyncPaymentService($this->createMock(SettingsService::class)),
            $repository
        );
        // A later payment must never reuse the invoice of an earlier one
        $this->runPrePayHook($handler);
        $this->runPrePayHook($handler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create iDEAL QR order');

        $handler->getMethodPayload($this->order(), new RequestDataBag(), $this->salesChannelContext(), 'idealqr');
    }

    private function runPrePayHook(IdealQrPaymentHandler $handler): void
    {
        $method = new \ReflectionMethod($handler, 'beforePayModern');
        $method->setAccessible(true);
        $method->invoke(
            $handler,
            new PaymentTransactionStruct(self::TRANSACTION_ID, 'https://shop.test/return'),
            new RequestDataBag(),
            Context::createDefaultContext()
        );
    }

    private function asyncPaymentService(SettingsService $settingsService): AsyncPaymentService
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setOrderId('order-id');
        $orderTransaction->setOrder($this->order());

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $asyncPaymentService->logger = $this->createMock(LoggerInterface::class);
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);
        $asyncPaymentService->method('getTransaction')->willReturn($orderTransaction);
        $asyncPaymentService->method('getSalesChannelContext')->willReturn($this->salesChannelContext());

        return $asyncPaymentService;
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setAmountTotal(100.0);

        return $order;
    }

    private function idealQrOrder(int $invoice): IdealQrOrderEntity
    {
        $entity = new IdealQrOrderEntity();
        $entity->setId('ideal-qr-order-id');
        $entity->setInvoice($invoice);

        return $entity;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $salesChannelContext;
    }
}
