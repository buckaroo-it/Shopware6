<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use Buckaroo\Shopware6\Service\SetPaymentOrderRouteDecorator;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changing the payment method of an existing order (storefront "change payment
 * method" page and `/store-api/order/payment`) checks the order total.
 */
class SetPaymentOrderRouteDecoratorTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $orderId;
    private string $customerId;

    protected function setUp(): void
    {
        $this->orderId = Uuid::randomHex();
        $this->customerId = Uuid::randomHex();
    }

    public function testRefusesPaymentMethodWhoseMaximumIsBelowTheOrderTotal(): void
    {
        $inner = $this->createMock(AbstractSetPaymentOrderRoute::class);
        $inner->expects(static::never())->method('setPayment');

        $decorator = $this->createDecorator($inner, AfterPayPaymentHandler::class, 1800.0);

        $this->expectException(OrderException::class);
        $decorator->setPayment($this->request(), $this->context());
    }

    public function testAllowsPaymentMethodWithinItsLimits(): void
    {
        $inner = $this->createMock(AbstractSetPaymentOrderRoute::class);
        $inner->expects(static::once())->method('setPayment')->willReturn(new SetPaymentOrderRouteResponse());

        $decorator = $this->createDecorator($inner, AfterPayPaymentHandler::class, 750.0);

        $decorator->setPayment($this->request(), $this->context());
    }

    public function testOtherPaymentProvidersAreLeftToShopware(): void
    {
        $inner = $this->createMock(AbstractSetPaymentOrderRoute::class);
        $inner->expects(static::once())->method('setPayment')->willReturn(new SetPaymentOrderRouteResponse());

        $decorator = $this->createDecorator($inner, 'Some\\Other\\PaymentHandler', 1800.0);

        $decorator->setPayment($this->request(), $this->context());
    }

    public function testOrderOfAnotherCustomerIsLeftToShopware(): void
    {
        $inner = $this->createMock(AbstractSetPaymentOrderRoute::class);
        $inner->expects(static::once())->method('setPayment')->willReturn(new SetPaymentOrderRouteResponse());

        // the order lookup is limited to the logged in customer, so it finds nothing
        $decorator = $this->createDecorator($inner, AfterPayPaymentHandler::class, null);

        $decorator->setPayment($this->request(), $this->context());
    }

    private function createDecorator(
        AbstractSetPaymentOrderRoute $inner,
        string $handler,
        ?float $orderTotal
    ): SetPaymentOrderRouteDecorator {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $key === 'afterpayMaxOrderAmount' ? 800.0 : null
        );

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(md5($handler));
        $paymentMethod->setHandlerIdentifier($handler);

        $order = null;
        if ($orderTotal !== null) {
            $order = new OrderEntity();
            $order->setId($this->orderId);
            $order->setSalesChannelId(self::SALES_CHANNEL_ID);
            $order->setAmountTotal($orderTotal);
            $order->setTotalRounding(new CashRoundingConfig(2, 0.01, true));
        }

        $customerId = $this->customerId;
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturnCallback(
            function (Criteria $criteria) use ($order, $customerId) {
                $filters = $criteria->getFilters();
                static::assertCount(1, $filters);
                static::assertInstanceOf(EqualsFilter::class, $filters[0]);
                static::assertSame('orderCustomer.customerId', $filters[0]->getField());
                static::assertSame($customerId, $filters[0]->getValue());

                return $this->searchResult('order', $order);
            }
        );

        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethodRepository->method('search')->willReturn($this->searchResult('payment_method', $paymentMethod));

        return new SetPaymentOrderRouteDecorator(
            $inner,
            new PaymentAmountLimitService($settingsService, $this->createMock(LoggerInterface::class)),
            $orderRepository,
            $paymentMethodRepository
        );
    }

    /**
     * @return EntitySearchResult<EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>>
     */
    private function searchResult(string $entity, ?object $element): EntitySearchResult
    {
        $collection = new EntityCollection();
        if ($element !== null) {
            $collection->add($element);
        }

        return new EntitySearchResult(
            $entity,
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext()
        );
    }

    private function request(): Request
    {
        $request = new Request();
        $request->request->set('orderId', $this->orderId);
        $request->request->set('paymentMethodId', Uuid::randomHex());

        return $request;
    }

    private function context(): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId($this->customerId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
