<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Storefront\Controller\IdealFastCheckoutController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\Request;

/**
 * A guest using iDEAL Fast Checkout gets a freshly loaded sales channel
 * context after registration. iDEAL must be selected on that context, which
 * is the one the order is created with.
 */
class IdealFastCheckoutGuestPaymentMethodTest extends TestCase
{
    public function testIdealIsSelectedOnTheContextTheOrderIsCreatedWith(): void
    {
        $anonymousContext = $this->context(null);
        $customer = new CustomerEntity();
        $customer->setId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $guestContext = $this->context($customer);

        $registerRoute = $this->createMock(AbstractRegisterRoute::class);
        $registerRoute->method('register')->willReturn(new CustomerResponse($customer));
        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->method('get')->willReturn($guestContext);

        $controller = new class (
            $this->createMock(CartService::class),
            $this->createMock(CustomerService::class),
            $this->createMock(OrderService::class),
            $this->createMock(SettingsService::class),
            $this->createMock(SalesChannelRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(SalesChannelContextPersister::class),
            $contextService,
            $registerRoute,
            $this->createMock(EntityRepository::class)
        ) extends IdealFastCheckoutController {
            /** @var list<SalesChannelContext> */
            public array $paymentMethodSetOn = [];

            public ?SalesChannelContext $orderCreatedWith = null;

            protected function overrideChannelPaymentMethod(
                SalesChannelContext $salesChannelContext,
                string $handler
            ): void {
                $this->paymentMethodSetOn[] = $salesChannelContext;
            }

            protected function getCart(Request $request, SalesChannelContext $salesChannelContext)
            {
                return new Cart('cart-token');
            }

            protected function createOrder(SalesChannelContext $salesChannelContext, string $cartToken)
            {
                $this->orderCreatedWith = $salesChannelContext;

                return new OrderEntity();
            }

            protected function placeOrder(
                OrderEntity $orderEntity,
                SalesChannelContext $salesChannelContext,
                RequestDataBag $data
            ) {
                return '/checkout/finish';
            }
        };

        $response = $controller->pay(new Request([], ['page' => 'product']), $anonymousContext);

        $this->assertStringContainsString('"error":false', (string) $response->getContent());
        $this->assertSame($guestContext, $controller->orderCreatedWith);
        $this->assertSame([$anonymousContext, $guestContext], $controller->paymentMethodSetOn);
    }

    private function context(?CustomerEntity $customer): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId('ffffffffffffffffffffffffffffffff');
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('55555555555555555555555555555555');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getToken')->willReturn('context-token');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getShippingLocation')->willReturn(ShippingLocation::createFromCountry($country));
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
