<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Buckaroo\Shopware6\Storefront\Controller\ApplePayController;
use Buckaroo\Shopware6\Storefront\Controller\GooglePayController;
use Buckaroo\Shopware6\Storefront\Controller\IdealFastCheckoutController;
use Buckaroo\Shopware6\Storefront\Controller\PaypalExpressController;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\ExpressOrderServiceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every express checkout controller creates its order from a cart loaded by
 * token. Whatever the request looks like (page value, contact data, logged-in
 * or guest shopper), the cart must be recalculated before the order is
 * persisted and a blocking error must stop the order.
 */
class ExpressCheckoutOrderCreationTest extends TestCase
{
    private const PAYMENT_METHOD_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private ExpressOrderServiceFactory $factory;

    private Cart $storedCart;

    /** @var CartService&MockObject */
    private CartService $cartService;

    /** @var SalesChannelContext&MockObject */
    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->factory = new ExpressOrderServiceFactory($this);
        $this->context = $this->factory->salesChannelContext();
        $this->storedCart = new Cart('cart-token');

        $this->cartService = $this->createMock(CartService::class);
        $this->cartService->method('setSaleChannelContext')->willReturnSelf();
        $this->cartService->method('load')->with('cart-token')->willReturn($this->storedCart);
        $this->cartService->method('calculateCart')->willReturnArgument(0);
    }

    public function testApplePayWithUnexpectedPageValueStillRecalculatesAndRefusesBlockedCart(): void
    {
        $this->givenLoggedInCustomer();
        $this->factory->recalculationReturns(new ProductOutOfStockError('dddddddddddddddddddddddddddddddd', 'Product'));
        $this->expectNoOrderPersisted();

        $this->expectException(CartException::class);
        $this->callCreateOrder(
            ApplePayController::class,
            $this->appleRequest('anything-else', ['token' => ['paymentData' => []]])
        );
    }

    public function testApplePayLoggedInCustomerWithoutContactDataStillRecalculatesAndRefusesBlockedCart(): void
    {
        $this->givenLoggedInCustomer();
        $this->factory->recalculationReturns(
            new PaymentMethodBlockedError('Apple Pay', 'rule not matching', self::PAYMENT_METHOD_ID)
        );
        $this->expectNoOrderPersisted();

        $this->expectException(CartException::class);
        $this->callCreateOrder(
            ApplePayController::class,
            $this->appleRequest('product', ['token' => ['paymentData' => []]])
        );
    }

    public function testApplePayValidCartCreatesExactlyOneOrder(): void
    {
        $this->givenLoggedInCustomer();
        $calculated = $this->factory->recalculationReturns();
        $this->factory->cartCalculator->expects($this->once())->method('calculate');
        $this->factory->orderPersister->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($calculated))
            ->willReturn(ExpressOrderServiceFactory::ORDER_ID);

        $order = $this->callCreateOrder(
            ApplePayController::class,
            $this->appleRequest('checkout', ['token' => ['paymentData' => []]])
        );

        $this->assertSame($this->factory->order, $order);
    }

    public function testIdealFastCheckoutRecalculatesReloadedCartAndRefusesOutOfStockProduct(): void
    {
        $this->givenLoggedInCustomer();
        $this->factory->recalculationReturns(new ProductOutOfStockError('dddddddddddddddddddddddddddddddd', 'Product'));
        $this->expectNoOrderPersisted();

        $this->expectException(CartException::class);
        $this->callCreateOrder(IdealFastCheckoutController::class, $this->context, 'cart-token');
    }

    public function testGooglePayRefusesInactivePaymentMethod(): void
    {
        $this->givenLoggedInCustomer();
        $this->factory->recalculationReturns(
            new PaymentMethodBlockedError('Google Pay', 'inactive', self::PAYMENT_METHOD_ID)
        );
        $this->expectNoOrderPersisted();

        $this->expectException(CartException::class);
        $this->callCreateOrder(
            GooglePayController::class,
            $this->context,
            new Request([], ['page' => 'checkout', 'cartToken' => 'cart-token']),
            $this->storedCart
        );
    }

    public function testPaypalExpressRefusesRuleBlockedPaymentMethod(): void
    {
        $this->givenLoggedInCustomer();
        $this->factory->recalculationReturns(
            new PaymentMethodBlockedError('PayPal', 'rule not matching', self::PAYMENT_METHOD_ID)
        );
        $this->expectNoOrderPersisted();

        $this->expectException(CartException::class);
        $this->callCreateOrder(PaypalExpressController::class, $this->context, 'cart-token');
    }

    private function givenLoggedInCustomer(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $customer->setGuest(false);
        $this->context->method('getCustomer')->willReturn($customer);
    }

    private function expectNoOrderPersisted(): void
    {
        $this->factory->cartCalculator->expects($this->once())
            ->method('calculate')
            ->with($this->storedCart, $this->context);
        $this->factory->orderPersister->expects($this->never())->method('persist');
        $this->factory->cartPersister->expects($this->never())->method('delete');
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function appleRequest(string $page, array $payment): Request
    {
        return new Request([], [
            'page' => $page,
            'cartToken' => 'cart-token',
            'payment' => json_encode($payment),
        ]);
    }

    /**
     * @param class-string<AbstractPaymentController> $controllerClass
     * @param mixed ...$arguments arguments for createOrder(); for Apple Pay only the request
     */
    private function callCreateOrder(string $controllerClass, mixed ...$arguments): OrderEntity
    {
        $reflection = new \ReflectionClass($controllerClass);
        $controller = $reflection->newInstanceWithoutConstructor();

        $properties = [
            'cartService' => $this->cartService,
            'customerService' => $this->createMock(CustomerService::class),
            'orderService' => $this->factory->create(),
        ];
        foreach ($properties as $name => $value) {
            $property = new \ReflectionProperty(AbstractPaymentController::class, $name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        if ($controllerClass === ApplePayController::class) {
            $arguments = [$this->context, $arguments[0]];
        }

        $method = $reflection->getMethod('createOrder');
        $method->setAccessible(true);

        $order = $method->invoke($controller, ...$arguments);
        $this->assertInstanceOf(OrderEntity::class, $order);

        return $order;
    }
}
