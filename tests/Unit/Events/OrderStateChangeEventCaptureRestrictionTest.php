<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Events\OrderStateChangeEvent;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\KlarnaMorService;
use Buckaroo\Shopware6\Service\NotificationServiceFactory;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Subscribers\OrderDeliveryWrittenSubscriber;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

/**
 * Capture-on-shipment may be triggered from two paths (see OrderStateChangeEvent):
 * the state-machine path (state_enter.order_delivery.state.shipped, fires once per
 * transition) and the direct DAL write path (order_delivery.written, fires on every
 * write that carries a stateId, including repeated writes of the state the delivery
 * already has).
 *
 * The DAL write path must be restricted to Klarna MoR. Klarna KP must not use it,
 * because a KP capture is a "Pay on reservation" whose reservation may already be
 * FullyCaptured at Buckaroo without the plugin having written
 * customFields['captured'] - so the canCapture* guards cannot deduplicate and each
 * write produces another Buckaroo 491 validation failure.
 */
class OrderStateChangeEventCaptureRestrictionTest extends TestCase
{
    private const ORDER_ID         = '44444444444444444444444444444444';
    private const SALES_CHANNEL_ID = '55555555555555555555555555555555';

    /** @var TransactionService&MockObject */
    private TransactionService $transactionService;

    /** @var OrderService&MockObject */
    private OrderService $orderService;

    /** @var CaptureService&MockObject */
    private CaptureService $captureService;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    private OrderStateChangeEvent $subject;

    /** @var Context&MockObject */
    private Context $context;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->orderService       = $this->createMock(OrderService::class);
        $this->captureService     = $this->createMock(CaptureService::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->context            = $this->createMock(Context::class);

        $notificationServiceFactory = $this->createMock(NotificationServiceFactory::class);
        $notificationServiceFactory->method('getNotificationService')->willReturn(new \stdClass());

        $this->settingsService->method('getSetting')->willReturn(true);

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setUniqueIdentifier(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $this->orderService->method('getOrderById')->willReturn($order);

        $this->subject = new OrderStateChangeEvent(
            $this->transactionService,
            $this->createMock(InvoiceService::class),
            $this->settingsService,
            $this->orderService,
            $this->createMock(LoggerInterface::class),
            $this->captureService,
            $notificationServiceFactory,
            $this->createMock(KlarnaMorService::class),
            $this->createMock(StateTransitionService::class)
        );
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function withCustomFields(array $customFields): void
    {
        $this->transactionService->method('getCustomFields')->willReturn($customFields);
    }

    /**
     * @return array<string, mixed>
     */
    private function klarnaKpCustomFields(): array
    {
        // A Klarna KP order whose reservation was captured outside of Shopware:
        // reservationNumber is present, 'captured' is not.
        return [
            'brqPaymentMethod'  => 'KlarnaKp',
            'serviceName'       => 'klarnakp',
            'reservationNumber' => '8ff10537-f735-4485-bdc8-3b19dd50f733',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function klarnaMorCustomFields(): array
    {
        return [
            'brqPaymentMethod' => 'klarna',
            'serviceName'      => 'klarna',
            'dataRequestKey'   => '65105EB3A3704E6D8FDCF8A1DCE8DD9E',
        ];
    }

    public function testDalWritePathDoesNotCaptureKlarnaKp(): void
    {
        $this->withCustomFields($this->klarnaKpCustomFields());

        $this->captureService
            ->expects($this->never())
            ->method('capture');

        $this->assertFalse(
            $this->subject->triggerCaptureForShippedOrder(
                self::ORDER_ID,
                self::SALES_CHANNEL_ID,
                $this->context,
                OrderDeliveryWrittenSubscriber::CAPTURE_METHODS_ON_DAL_WRITE
            )
        );
    }

    public function testDalWritePathStillCapturesKlarnaMor(): void
    {
        $this->withCustomFields($this->klarnaMorCustomFields());

        $this->captureService
            ->expects($this->once())
            ->method('capture')
            ->willReturn(['status' => true, 'message' => 'captured']);

        $this->assertTrue(
            $this->subject->triggerCaptureForShippedOrder(
                self::ORDER_ID,
                self::SALES_CHANNEL_ID,
                $this->context,
                OrderDeliveryWrittenSubscriber::CAPTURE_METHODS_ON_DAL_WRITE
            )
        );
    }

    /**
     * No restriction (state_enter path): Klarna KP capture-on-shipment keeps working
     * exactly as it did before, i.e. once per shipped transition.
     */
    public function testStateMachinePathStillCapturesKlarnaKp(): void
    {
        $this->withCustomFields($this->klarnaKpCustomFields());

        $this->captureService
            ->expects($this->once())
            ->method('capture')
            ->willReturn(['status' => true, 'message' => 'captured']);

        $this->assertTrue(
            $this->subject->triggerCaptureForShippedOrder(
                self::ORDER_ID,
                self::SALES_CHANNEL_ID,
                $this->context
            )
        );
    }

    /**
     * An already captured Klarna KP order must never be paid again, on either path.
     */
    public function testCapturedKlarnaKpOrderIsNeverCapturedAgain(): void
    {
        $this->withCustomFields($this->klarnaKpCustomFields() + ['captured' => 1]);

        $this->captureService
            ->expects($this->never())
            ->method('capture');

        $this->subject->triggerCaptureForShippedOrder(
            self::ORDER_ID,
            self::SALES_CHANNEL_ID,
            $this->context
        );
    }
}
