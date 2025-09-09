<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Events\OrderStateChangeEvent;
use Shopware\Administration\Notification\NotificationService;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

class OrderStateChangeEventTest extends TestCase
{
    private OrderStateChangeEvent $orderStateChangeEvent;
    private MockObject $transactionService;
    private MockObject $invoiceService;
    private MockObject $settingsService;
    private MockObject $orderService;
    private MockObject $captureService;
    private MockObject $notificationService;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->captureService = $this->createMock(CaptureService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->orderStateChangeEvent = new OrderStateChangeEvent(
            $this->transactionService,
            $this->invoiceService,
            $this->settingsService,
            $this->orderService,
            $this->logger,
            $this->captureService,
            $this->notificationService
        );
    }

    public function testOnOrderDeliveryStateShippedWithNullSalesChannelId(): void
    {
        // Arrange
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        
        $eventOrder = new OrderEntity();
        $eventOrder->setId($orderId);
        
        $order = new OrderEntity();
        $order->setId($orderId);
        
        $event = $this->createMock(OrderStateMachineStateChangeEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getOrder')->willReturn($eventOrder);
        $event->method('getSalesChannelId')->willReturn(null); // Null sales channel ID
        
        $this->orderService
            ->expects($this->once())
            ->method('getOrderById')
            ->with($orderId, $this->anything(), $context)
            ->willReturn($order);
            
        $this->transactionService
            ->expects($this->once())
            ->method('getCustomFields')
            ->with($order, $context)
            ->willReturn(['brqPaymentMethod' => 'Billink']);
        
        // Expect warning to be logged for null sales channel ID
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Cannot process order state change: sales channel ID is null',
                ['orderId' => $orderId]
            );

        // Act
        $result = $this->orderStateChangeEvent->onOrderDeliveryStateShipped($event);

        // Assert
        $this->assertFalse($result);
    }

    public function testCanCaptureAfterPayWithNullSalesChannelId(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->orderStateChangeEvent);
        $method = $reflection->getMethod('canCaptureAfterpay');
        $method->setAccessible(true);

        // Arrange
        $customFields = ['brqPaymentMethod' => 'afterpay'];
        $orderCustomFields = [CaptureService::ORDER_IS_AUTHORIZED => true];
        $salesChannelId = null; // Null sales channel ID

        // Expect warning to be logged
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Cannot determine afterpay capture settings: sales channel ID is null');

        // Act
        $result = $method->invoke(
            $this->orderStateChangeEvent,
            $customFields,
            $orderCustomFields,
            $salesChannelId
        );

        // Assert
        $this->assertFalse($result);
    }

    public function testCanCaptureAfterPayWithValidSalesChannelId(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->orderStateChangeEvent);
        $method = $reflection->getMethod('canCaptureAfterpay');
        $method->setAccessible(true);

        // Arrange
        $customFields = ['brqPaymentMethod' => 'afterpay'];
        $orderCustomFields = [CaptureService::ORDER_IS_AUTHORIZED => true];
        $salesChannelId = Uuid::randomHex();

        $this->settingsService
            ->expects($this->once())
            ->method('getSetting')
            ->with('afterpayCaptureonshippent', $salesChannelId)
            ->willReturn(true);

        // Act
        $result = $method->invoke(
            $this->orderStateChangeEvent,
            $customFields,
            $orderCustomFields,
            $salesChannelId
        );

        // Assert
        $this->assertTrue($result);
    }

    public function testCanCaptureAfterPayWithInvalidPaymentMethodType(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->orderStateChangeEvent);
        $method = $reflection->getMethod('canCaptureAfterpay');
        $method->setAccessible(true);

        // Arrange - non-string payment method
        $customFields = ['brqPaymentMethod' => 123]; // Integer instead of string
        $orderCustomFields = [CaptureService::ORDER_IS_AUTHORIZED => true];
        $salesChannelId = Uuid::randomHex();

        // Act
        $result = $method->invoke(
            $this->orderStateChangeEvent,
            $customFields,
            $orderCustomFields,
            $salesChannelId
        );

        // Assert
        $this->assertFalse($result);
    }

    public function testCanCaptureAfterPayWithMissingPaymentMethod(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->orderStateChangeEvent);
        $method = $reflection->getMethod('canCaptureAfterpay');
        $method->setAccessible(true);

        // Arrange - missing payment method
        $customFields = []; // No brqPaymentMethod
        $orderCustomFields = [CaptureService::ORDER_IS_AUTHORIZED => true];
        $salesChannelId = Uuid::randomHex();

        // Act
        $result = $method->invoke(
            $this->orderStateChangeEvent,
            $customFields,
            $orderCustomFields,
            $salesChannelId
        );

        // Assert
        $this->assertFalse($result);
    }
}
