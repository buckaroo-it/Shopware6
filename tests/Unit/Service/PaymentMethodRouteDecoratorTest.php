<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\KlarnaPaymentHandler;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\PaymentMethodRouteDecorator;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Store API payment method route (`/store-api/payment-method`, also used by the
 * storefront checkout): restricted Buckaroo methods are removed from the available
 * payment methods based on the current cart total.
 */
class PaymentMethodRouteDecoratorTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_PROVIDER = 'Some\\Other\\PaymentHandler';

    /** @var CartService&MockObject */
    private CartService $cartService;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->cartService = $this->createMock(CartService::class);
        $this->requestStack = new RequestStack();
    }

    public function testRemovesBuckarooMethodsOutsideTheirLimits(): void
    {
        $this->cartService->method('getCart')->willReturn($this->cart(900.0));

        $decorator = $this->createDecorator([
            'afterpayMaxOrderAmount' => 800.0,
            'klarnaMaxOrderAmount' => 1000.0,
        ]);

        $response = $decorator->load($this->availableRequest(), $this->context(), new Criteria());

        static::assertSame(
            [IdealPaymentHandler::class, KlarnaPaymentHandler::class, self::OTHER_PROVIDER],
            $this->handlers($response)
        );
        static::assertSame(3, $this->total($response));
    }

    public function testKeepsAllMethodsWithinTheLimits(): void
    {
        $this->cartService->method('getCart')->willReturn($this->cart(800.0));

        $decorator = $this->createDecorator(['afterpayMaxOrderAmount' => 800.0]);

        $response = $decorator->load($this->availableRequest(), $this->context(), new Criteria());

        static::assertCount(4, $response->getPaymentMethods());
    }

    public function testOnlyAvailableInTheRequestBodyIsSupported(): void
    {
        $this->cartService->method('getCart')->willReturn($this->cart(900.0));

        $decorator = $this->createDecorator(['afterpayMaxOrderAmount' => 800.0]);

        $request = new Request();
        $request->request->set('onlyAvailable', true);
        $response = $decorator->load($request, $this->context(), new Criteria());

        static::assertNotContains(AfterPayPaymentHandler::class, $this->handlers($response));
    }

    public function testListOfAllPaymentMethodsIsNotFiltered(): void
    {
        $this->cartService->expects(static::never())->method('getCart');

        $decorator = $this->createDecorator(['afterpayMaxOrderAmount' => 800.0]);

        $response = $decorator->load(new Request(), $this->context(), new Criteria());

        static::assertCount(4, $response->getPaymentMethods());
    }

    public function testCartIsNotLoadedWithoutConfiguredLimits(): void
    {
        $this->cartService->expects(static::never())->method('getCart');

        $decorator = $this->createDecorator([]);

        $response = $decorator->load($this->availableRequest(), $this->context(), new Criteria());

        static::assertCount(4, $response->getPaymentMethods());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function orderRouteProvider(): iterable
    {
        yield 'edit order page' => ['frontend.account.edit-order.page'];
        yield 'storefront payment change' => ['frontend.account.edit-order.change-payment-method'];
        yield 'storefront order update' => ['frontend.account.edit-order.update-order'];
        yield 'store api order payment' => ['store-api.order.set-payment'];
    }

    /**
     * Payment methods of an existing order are checked against the order total
     * elsewhere; the cart of the customer is unrelated there.
     *
     * @dataProvider orderRouteProvider
     */
    public function testRequestsAboutAnExistingOrderAreLeftAlone(string $route): void
    {
        $this->cartService->expects(static::never())->method('getCart');

        $mainRequest = new Request();
        $mainRequest->attributes->set('_route', $route);
        $this->requestStack->push($mainRequest);

        $decorator = $this->createDecorator(['afterpayMaxOrderAmount' => 800.0]);

        $response = $decorator->load($this->availableRequest(), $this->context(), new Criteria());

        static::assertCount(4, $response->getPaymentMethods());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createDecorator(array $settings): PaymentMethodRouteDecorator
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        $inner = $this->createMock(AbstractPaymentMethodRoute::class);
        $inner->method('load')->willReturnCallback(fn () => $this->innerResponse());

        return new PaymentMethodRouteDecorator(
            $inner,
            new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class)),
            $this->cartService,
            $this->requestStack
        );
    }

    private function innerResponse(): PaymentMethodRouteResponse
    {
        $collection = new PaymentMethodCollection();
        $handlers = [
            AfterPayPaymentHandler::class,
            IdealPaymentHandler::class,
            KlarnaPaymentHandler::class,
            self::OTHER_PROVIDER,
        ];
        foreach ($handlers as $handler) {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId(md5($handler));
            $paymentMethod->setHandlerIdentifier($handler);
            $collection->add($paymentMethod);
        }

        return new PaymentMethodRouteResponse(new EntitySearchResult(
            'payment_method',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext()
        ));
    }

    private function availableRequest(): Request
    {
        return new Request(['onlyAvailable' => '1']);
    }

    private function cart(float $totalPrice): Cart
    {
        $cart = new Cart('token');
        $cart->setPrice(new CartPrice(
            $totalPrice,
            $totalPrice,
            $totalPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $cart;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getToken')->willReturn('token');
        $context->method('getTotalRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));

        return $context;
    }

    /**
     * @return list<string>
     */
    private function handlers(PaymentMethodRouteResponse $response): array
    {
        return array_values($response->getPaymentMethods()->map(
            static fn (PaymentMethodEntity $paymentMethod) => $paymentMethod->getHandlerIdentifier()
        ));
    }

    private function total(PaymentMethodRouteResponse $response): int
    {
        $result = $response->getObject();
        static::assertInstanceOf(EntitySearchResult::class, $result);

        return $result->getTotal();
    }
}
