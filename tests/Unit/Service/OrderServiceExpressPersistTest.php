<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\ExpressOrderServiceFactory;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Express checkout orders are created through OrderService::persist(). The
 * cart handed in may come straight from storage, so the service itself must
 * recalculate it under the current context and only persist a cart without
 * blocking errors.
 */
class OrderServiceExpressPersistTest extends TestCase
{
    private const PAYMENT_METHOD_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SHIPPING_METHOD_ID = 'cccccccccccccccccccccccccccccccc';

    private ExpressOrderServiceFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ExpressOrderServiceFactory($this);
    }

    public function testValidCartIsRecalculatedAndTheRecalculatedCartIsPersisted(): void
    {
        $storedCart = new Cart('cart-token');
        $context = $this->factory->salesChannelContext();
        $calculated = $this->factory->recalculationReturns();

        $this->factory->cartCalculator->expects($this->once())
            ->method('calculate')
            ->with($storedCart, $context);
        $this->factory->orderPersister->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($calculated), $context)
            ->willReturn(ExpressOrderServiceFactory::ORDER_ID);
        $this->factory->cartPersister->expects($this->once())
            ->method('delete')
            ->with('cart-token', $context);
        $this->factory->lock->expects($this->once())->method('release');

        $order = $this->factory->create()->setSaleChannelContext($context)->persist($storedCart);

        $this->assertSame($this->factory->order, $order);
        $this->assertCount(1, $this->factory->paymentProcessor->validated);
        $this->assertSame($calculated, $this->factory->paymentProcessor->validated[0][0]);
        $this->assertSame($context, $this->factory->paymentProcessor->validated[0][1]);
    }

    public function testCartIsLockedByItsToken(): void
    {
        $this->factory->recalculationReturns();
        $this->factory->orderPersister->method('persist')->willReturn(ExpressOrderServiceFactory::ORDER_ID);
        $this->factory->lockFactory->expects($this->once())
            ->method('createLock')
            ->with('cart-lockcart-token');

        $this->factory->create()
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));
    }

    /**
     * @return iterable<string, array{Error}>
     */
    public static function blockingErrors(): iterable
    {
        yield 'product out of stock' => [new ProductOutOfStockError('dddddddddddddddddddddddddddddddd', 'Product')];
        yield 'payment method inactive' => [
            new PaymentMethodBlockedError('Apple Pay', 'inactive', self::PAYMENT_METHOD_ID),
        ];
        yield 'payment method rule not matching' => [
            new PaymentMethodBlockedError('Apple Pay', 'rule not matching', self::PAYMENT_METHOD_ID),
        ];
        yield 'shipping method blocked' => [
            new ShippingMethodBlockedError('Standard', self::SHIPPING_METHOD_ID, 'not available'),
        ];
    }

    /**
     * @dataProvider blockingErrors
     */
    public function testBlockingErrorFoundOnRecalculationPreventsTheOrder(Error $error): void
    {
        // The stored cart has no errors: they were dropped when it was saved.
        $storedCart = new Cart('cart-token');
        $this->assertCount(0, $storedCart->getErrors());
        $this->factory->recalculationReturns($error);

        $this->factory->orderPersister->expects($this->never())->method('persist');
        $this->factory->cartPersister->expects($this->never())->method('delete');
        $this->factory->lock->expects($this->once())->method('release');

        try {
            $this->factory->create()
                ->setSaleChannelContext($this->factory->salesChannelContext())
                ->persist($storedCart);
            $this->fail('Expected the order to be refused');
        } catch (CartException $e) {
            $this->assertSame([], $this->factory->paymentProcessor->validated);
        }
    }

    public function testCartAlreadyBeingProcessedIsRefused(): void
    {
        $busyLock = $this->createMock(SharedLockInterface::class);
        $busyLock->method('acquire')->willReturn(false);
        $this->factory->lockFactory = $this->createMock(LockFactory::class);
        $this->factory->lockFactory->method('createLock')->willReturn($busyLock);

        $this->factory->cartCalculator->expects($this->never())->method('calculate');
        $this->factory->orderPersister->expects($this->never())->method('persist');
        $busyLock->expects($this->never())->method('release');

        $this->expectException(CreateCartException::class);
        $this->factory->create()
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));
    }

    public function testCartThatWasAlreadyOrderedIsRefused(): void
    {
        // A second request for the same cart arrives after the first one turned
        // it into an order and removed it from storage.
        $this->factory->cartPersister->method('load')
            ->willThrowException(new \RuntimeException('Cart not found'));

        $this->factory->cartCalculator->expects($this->never())->method('calculate');
        $this->factory->orderPersister->expects($this->never())->method('persist');
        $this->factory->lock->expects($this->once())->method('release');

        $this->expectException(CreateCartException::class);
        $this->factory->create()
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));
    }

    public function testFailedPaymentValidationPreventsTheOrder(): void
    {
        $this->factory->recalculationReturns();
        $this->factory->paymentProcessor->failWith = new \RuntimeException('payment validation failed');

        $this->factory->orderPersister->expects($this->never())->method('persist');
        $this->factory->cartPersister->expects($this->never())->method('delete');
        $this->factory->lock->expects($this->once())->method('release');

        $this->expectException(\RuntimeException::class);
        $this->factory->create()
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));
    }

    public function testPaymentServiceWithoutValidationStepStillPersists(): void
    {
        // Shopware 6.5/6.6.0: the legacy PaymentService has no validate().
        $this->factory->recalculationReturns();
        $this->factory->orderPersister->expects($this->once())
            ->method('persist')
            ->willReturn(ExpressOrderServiceFactory::ORDER_ID);

        $order = $this->factory->create(new \stdClass())
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));

        $this->assertSame($this->factory->order, $order);
    }

    public function testFailedCartCleanupDoesNotFailThePlacedOrder(): void
    {
        $this->factory->recalculationReturns();
        $this->factory->orderPersister->method('persist')->willReturn(ExpressOrderServiceFactory::ORDER_ID);
        $this->factory->cartPersister->method('delete')
            ->willThrowException(new \RuntimeException('delete failed'));

        $order = $this->factory->create()
            ->setSaleChannelContext($this->factory->salesChannelContext())
            ->persist(new Cart('cart-token'));

        $this->assertSame($this->factory->order, $order);
    }
}
