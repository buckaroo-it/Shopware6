<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\ProcessedPushService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * "Order status after payment" sets the selected order status: Done must end in Done,
 * not In progress, and Open leaves the order as it is.
 */
class PushControllerOrderStatusTest extends TestCase
{
    public function testDoneMovesAnOpenOrderThroughInProgressToDone(): void
    {
        $this->assertSame(['process', 'complete'], $this->transitionsFor('complete', 'open'));
    }

    public function testDoneOnAnOrderInProgressOnlyCompletesIt(): void
    {
        $this->assertSame(['complete'], $this->transitionsFor('complete', 'in_progress'));
    }

    public function testInProgressSetsInProgress(): void
    {
        $this->assertSame(['process'], $this->transitionsFor('process', 'open'));
    }

    public function testOpenLeavesTheOrderAsItIs(): void
    {
        $this->assertSame([], $this->transitionsFor('open', 'open'));
        $this->assertSame([], $this->transitionsFor(null, 'open'));
    }

    /**
     * An authorized (e.g. Klarna reservation) or partially paid order is not Done yet
     */
    public function testDoneOnlyCompletesFullyPaidOrders(): void
    {
        $this->assertSame(['process'], $this->transitionsFor('complete', 'open', false));
        $this->assertSame([], $this->transitionsFor('complete', 'in_progress', false));
        $this->assertSame(['process'], $this->transitionsFor('process', 'open', false));
    }

    /**
     * Repeated pushes, or an order a flow already moved on, must not attempt transitions
     * Shopware does not allow (each one is logged as an error)
     */
    public function testOrderAlreadyAtOrPastTheStatusIsLeftAlone(): void
    {
        $this->assertSame([], $this->transitionsFor('process', 'in_progress'));
        $this->assertSame([], $this->transitionsFor('process', 'completed'));
        $this->assertSame([], $this->transitionsFor('complete', 'completed'));
        $this->assertSame([], $this->transitionsFor('complete', 'cancelled'));
    }

    /**
     * @return array<int, string> transitions applied to the order, in order
     */
    private function transitionsFor(?string $setting, string $currentState, bool $isFullyPaid = true): array
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getSettingsValue')->with('orderStatus', 'sales-channel-id')->willReturn($setting);

        $transitions = [];
        $stateTransitionService = $this->createMock(StateTransitionService::class);
        $stateTransitionService->method('changeOrderStatus')->willReturnCallback(
            static function (OrderEntity $order, Context $context, string $transition) use (&$transitions): void {
                $transitions[] = $transition;
            }
        );

        $state = new StateMachineStateEntity();
        $state->setTechnicalName($currentState);
        $order = new OrderEntity();
        $order->setStateMachineState($state);

        $controller = new PushController(
            $this->createMock(SignatureValidationService::class),
            $this->createMock(TransactionService::class),
            $stateTransitionService,
            $this->createMock(InvoiceService::class),
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(IdealQrOrderRepository::class),
            $this->createMock(OrderService::class),
            $this->createMock(CustomerService::class),
            $this->createMock(ProcessedPushService::class),
            $this->createMock(SalesChannelContextServiceInterface::class)
        );

        $method = new \ReflectionMethod($controller, 'applyOrderStatusAfterPayment');
        $method->setAccessible(true);
        $method->invoke($controller, $order, Context::createDefaultContext(), 'sales-channel-id', $isFullyPaid);

        return $transitions;
    }
}
