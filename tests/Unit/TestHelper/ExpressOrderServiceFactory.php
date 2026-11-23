<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\TestHelper;

use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\PaymentServiceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Builds a real OrderService around mocked core collaborators, so tests can
 * drive the express order creation path end to end without a database.
 */
final class ExpressOrderServiceFactory
{
    public const ORDER_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var CartCalculator&MockObject */
    public CartCalculator $cartCalculator;

    /** @var AbstractCartPersister&MockObject */
    public AbstractCartPersister $cartPersister;

    /** @var OrderPersisterInterface&MockObject */
    public OrderPersisterInterface $orderPersister;

    /** @var SharedLockInterface&MockObject */
    public SharedLockInterface $lock;

    /** @var LockFactory&MockObject */
    public LockFactory $lockFactory;

    /** @var EntityRepository&MockObject */
    public EntityRepository $orderRepository;

    public PaymentValidatorSpy $paymentProcessor;

    public OrderEntity $order;

    public function __construct(private readonly TestCase $test)
    {
        $this->cartCalculator = $this->mock(CartCalculator::class);
        $this->cartPersister = $this->mock(AbstractCartPersister::class);
        $this->orderPersister = $this->mock(OrderPersisterInterface::class);
        $this->lock = $this->mock(SharedLockInterface::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->lockFactory = $this->mock(LockFactory::class);
        $this->lockFactory->method('createLock')->willReturn($this->lock);
        $this->paymentProcessor = new PaymentValidatorSpy();

        $this->order = new OrderEntity();
        $this->order->setId(self::ORDER_ID);

        $searchResult = $this->mock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new OrderCollection([$this->order]));
        $this->orderRepository = $this->mock(EntityRepository::class);
        $this->orderRepository->method('search')->willReturn($searchResult);
    }

    public function create(?object $paymentService = null): OrderService
    {
        $paymentServiceFactory = $this->mock(PaymentServiceFactory::class);
        $paymentServiceFactory->method('getPaymentService')->willReturn($paymentService ?? $this->paymentProcessor);

        return new OrderService(
            $this->orderPersister,
            $this->orderRepository,
            $this->mock(EventDispatcherInterface::class),
            $paymentServiceFactory,
            $this->mock(EntityRepository::class),
            $this->mock(LoggerInterface::class),
            $this->cartCalculator,
            $this->cartPersister,
            $this->lockFactory
        );
    }

    /**
     * @return SalesChannelContext&MockObject
     */
    public function salesChannelContext(): SalesChannelContext
    {
        $context = $this->mock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getToken')->willReturn('context-token');

        return $context;
    }

    /**
     * Stored carts come back from the persister without their calculation
     * errors; the given errors only appear once the cart is recalculated.
     */
    public function recalculationReturns(Error ...$errors): Cart
    {
        $calculated = new Cart('cart-token');
        $calculated->setErrors(new ErrorCollection($errors));
        $this->cartCalculator->method('calculate')->willReturn($calculated);

        return $calculated;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&MockObject
     */
    private function mock(string $class): MockObject
    {
        $method = new \ReflectionMethod(TestCase::class, 'createMock');
        $method->setAccessible(true);

        /** @var T&MockObject */
        return $method->invoke($this->test, $class);
    }
}
