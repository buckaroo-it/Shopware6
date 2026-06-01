<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Events\OrderStateChangeEvent;
use Buckaroo\Shopware6\Subscribers\OrderDeliveryWrittenSubscriber;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;

class OrderDeliveryWrittenSubscriberTest extends TestCase
{
    private const SHIPPED_STATE_ID = '11111111111111111111111111111111';
    private const OPEN_STATE_ID    = '22222222222222222222222222222222';
    private const DELIVERY_ID      = '33333333333333333333333333333333';
    private const ORDER_ID         = '44444444444444444444444444444444';
    private const SALES_CHANNEL_ID = '55555555555555555555555555555555';

    /** @var OrderStateChangeEvent&MockObject */
    private OrderStateChangeEvent $orderStateChangeEvent;

    /** @var EntityRepository&MockObject */
    private EntityRepository $orderDeliveryRepository;

    /** @var EntityRepository&MockObject */
    private EntityRepository $stateMachineStateRepository;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private OrderDeliveryWrittenSubscriber $subscriber;

    /** @var Context&MockObject */
    private Context $context;

    protected function setUp(): void
    {
        $this->orderStateChangeEvent = $this->createMock(OrderStateChangeEvent::class);
        $this->orderDeliveryRepository = $this->createMock(EntityRepository::class);
        $this->stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->context = $this->createMock(Context::class);

        $this->subscriber = new OrderDeliveryWrittenSubscriber(
            $this->orderStateChangeEvent,
            $this->orderDeliveryRepository,
            $this->stateMachineStateRepository,
            $this->logger
        );
    }

    public function testSubscribesToOrderDeliveryWrittenEvent(): void
    {
        $events = OrderDeliveryWrittenSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(
            OrderDeliveryDefinition::ENTITY_NAME . '.written',
            $events
        );
        $this->assertSame('onOrderDeliveryWritten', $events[OrderDeliveryDefinition::ENTITY_NAME . '.written']);
    }

    /**
     * Payload that does not contain stateId must be ignored: the subscriber
     * must not delegate to OrderStateChangeEvent and must not even hit the
     * state-machine repository (zero-cost for unrelated PATCHes).
     */
    public function testIgnoresWritesWithoutStateIdInPayload(): void
    {
        $writeResult = new EntityWriteResult(
            self::DELIVERY_ID,
            ['trackingCodes' => ['ABC123']],
            OrderDeliveryDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = new EntityWrittenEvent(
            OrderDeliveryDefinition::ENTITY_NAME,
            [$writeResult],
            $this->context
        );

        $this->stateMachineStateRepository
            ->expects($this->never())
            ->method('search');

        $this->orderDeliveryRepository
            ->expects($this->never())
            ->method('search');

        $this->orderStateChangeEvent
            ->expects($this->never())
            ->method('triggerCaptureForShippedOrder');

        $this->subscriber->onOrderDeliveryWritten($event);
    }

    /**
     * stateId in payload but resolved technical name is NOT "shipped":
     * subscriber must not delegate.
     */
    public function testIgnoresWritesWhenNewStateIsNotShipped(): void
    {
        $writeResult = new EntityWriteResult(
            self::DELIVERY_ID,
            ['stateId' => self::OPEN_STATE_ID],
            OrderDeliveryDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = new EntityWrittenEvent(
            OrderDeliveryDefinition::ENTITY_NAME,
            [$writeResult],
            $this->context
        );

        $this->stubStateMachineStateMap();

        $this->orderDeliveryRepository
            ->expects($this->never())
            ->method('search');

        $this->orderStateChangeEvent
            ->expects($this->never())
            ->method('triggerCaptureForShippedOrder');

        $this->subscriber->onOrderDeliveryWritten($event);
    }

    /**
     * stateId in payload resolves to "shipped": subscriber must delegate to
     * OrderStateChangeEvent::triggerCaptureForShippedOrder with the parent
     * order's id, salesChannelId, and the same context.
     */
    public function testDelegatesToOrderStateChangeEventWhenNewStateIsShipped(): void
    {
        $writeResult = new EntityWriteResult(
            self::DELIVERY_ID,
            ['stateId' => self::SHIPPED_STATE_ID],
            OrderDeliveryDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = new EntityWrittenEvent(
            OrderDeliveryDefinition::ENTITY_NAME,
            [$writeResult],
            $this->context
        );

        $this->stubStateMachineStateMap();
        $this->stubOrderDeliveryLookup();

        $this->orderStateChangeEvent
            ->expects($this->once())
            ->method('triggerCaptureForShippedOrder')
            ->with(
                self::ORDER_ID,
                self::SALES_CHANNEL_ID,
                $this->identicalTo($this->context)
            );

        $this->subscriber->onOrderDeliveryWritten($event);
    }

    /**
     * Subscriber must swallow throwables from the downstream capture trigger
     * so the merchant's delivery write is never rolled back. The error must
     * be logged.
     */
    public function testSwallowsAndLogsDownstreamErrors(): void
    {
        $writeResult = new EntityWriteResult(
            self::DELIVERY_ID,
            ['stateId' => self::SHIPPED_STATE_ID],
            OrderDeliveryDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = new EntityWrittenEvent(
            OrderDeliveryDefinition::ENTITY_NAME,
            [$writeResult],
            $this->context
        );

        $this->stubStateMachineStateMap();
        $this->stubOrderDeliveryLookup();

        $this->orderStateChangeEvent
            ->method('triggerCaptureForShippedOrder')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('OrderDeliveryWrittenSubscriber failed'),
                $this->arrayHasKey('exception')
            );

        $this->subscriber->onOrderDeliveryWritten($event);
    }

    private function stubStateMachineStateMap(): void
    {
        $stateMachine = new StateMachineEntity();
        $stateMachine->setId('order_delivery_state_machine');
        $stateMachine->setUniqueIdentifier('order_delivery_state_machine');
        $stateMachine->setTechnicalName(OrderDeliveryStates::STATE_MACHINE);

        $shipped = new StateMachineStateEntity();
        $shipped->setId(self::SHIPPED_STATE_ID);
        $shipped->setUniqueIdentifier(self::SHIPPED_STATE_ID);
        $shipped->setTechnicalName(OrderDeliveryStates::STATE_SHIPPED);
        $shipped->setStateMachine($stateMachine);

        $open = new StateMachineStateEntity();
        $open->setId(self::OPEN_STATE_ID);
        $open->setUniqueIdentifier(self::OPEN_STATE_ID);
        $open->setTechnicalName(OrderDeliveryStates::STATE_OPEN);
        $open->setStateMachine($stateMachine);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new \ArrayIterator([$shipped, $open]));

        $this->stateMachineStateRepository
            ->method('search')
            ->willReturn($searchResult);
    }

    private function stubOrderDeliveryLookup(): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setUniqueIdentifier(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);

        $delivery = new OrderDeliveryEntity();
        $delivery->setId(self::DELIVERY_ID);
        $delivery->setUniqueIdentifier(self::DELIVERY_ID);
        $delivery->setOrder($order);

        $deliveryResult = $this->createMock(EntitySearchResult::class);
        $deliveryResult->method('getIterator')->willReturn(new \ArrayIterator([$delivery]));

        $this->orderDeliveryRepository
            ->method('search')
            ->willReturn($deliveryResult);
    }
}
