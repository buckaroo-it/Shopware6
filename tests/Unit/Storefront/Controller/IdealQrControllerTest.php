<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Storefront\Controller\IdealQrController;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The iDEAL QR page used to show whatever image URL its query string named, for any
 * order id, and the status poll answered for any order. A link on the shop's own domain
 * could therefore show a QR paying somebody else. The page now shows the image Buckaroo
 * returned, stored on the transaction, and only for an order of the current shopper.
 */
class IdealQrControllerTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    private const CUSTOMER_ID = 'customer-id';

    private const ORDER_ID = 'order-id';

    private const QR_IMAGE = 'https://qr.example/ideal-qr/image.png';

    private const FOREIGN_QR_IMAGE = 'https://attacker.test/qr.png';

    public function testThePageIsNotShownWithoutACustomerInTheSession(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('getOrderById');

        $response = $this->displayPage(
            new Request(['orderId' => self::ORDER_ID, 'qrImage' => self::FOREIGN_QR_IMAGE]),
            $orderService,
            null
        );

        $this->assertRedirectedToTheCart($response);
    }

    public function testThePageIsNotShownForTheOrderOfAnotherCustomer(): void
    {
        $response = $this->displayPage(
            new Request(['orderId' => self::ORDER_ID]),
            $this->orderService($this->order('another-customer-id', self::QR_IMAGE)),
            self::CUSTOMER_ID
        );

        $this->assertRedirectedToTheCart($response);
    }

    public function testThePageIsNotShownForTheOrderOfAnotherSalesChannel(): void
    {
        $order = $this->order(self::CUSTOMER_ID, self::QR_IMAGE);
        $order->setSalesChannelId('another-sales-channel-id');

        $response = $this->displayPage(
            new Request(['orderId' => self::ORDER_ID]),
            $this->orderService($order),
            self::CUSTOMER_ID
        );

        $this->assertRedirectedToTheCart($response);
    }

    public function testAnImageUrlInTheQueryStringIsNotShown(): void
    {
        $response = $this->displayPage(
            new Request(['orderId' => self::ORDER_ID, 'qrImage' => self::FOREIGN_QR_IMAGE]),
            $this->orderService($this->order(self::CUSTOMER_ID, null)),
            self::CUSTOMER_ID
        );

        $this->assertRedirectedToTheCart($response);
    }

    public function testThePageShowsTheImageStoredOnTheTransactionOfTheShoppersOrder(): void
    {
        $response = $this->displayPage(
            new Request(['orderId' => self::ORDER_ID, 'qrImage' => self::FOREIGN_QR_IMAGE]),
            $this->orderService($this->order(self::CUSTOMER_ID, self::QR_IMAGE)),
            self::CUSTOMER_ID
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $rendered = json_decode((string)$response->getContent(), true);
        $this->assertSame('@BuckarooPayments/storefront/buckaroo/ideal-qr.html.twig', $rendered['view']);
        $this->assertSame(self::QR_IMAGE, $rendered['parameters']['qrImage']);
        $this->assertSame(self::ORDER_ID, $rendered['parameters']['orderId']);
    }

    public function testTheStatusIsNotReportedForTheOrderOfAnotherCustomer(): void
    {
        $response = $this->pullStatus(
            $this->orderService($this->order('another-customer-id', self::QR_IMAGE, 'open')),
            self::CUSTOMER_ID
        );

        $this->assertSame(
            ['error' => true, 'message' => 'Cannot find order'],
            json_decode((string)$response->getContent(), true)
        );
    }

    public function testTheStatusOfTheShoppersOrderIsReported(): void
    {
        $response = $this->pullStatus(
            $this->orderService($this->order(self::CUSTOMER_ID, self::QR_IMAGE, 'open')),
            self::CUSTOMER_ID
        );

        $this->assertSame(['state' => 'open'], json_decode((string)$response->getContent(), true));
    }

    private function displayPage(Request $request, OrderService $orderService, ?string $customerId): Response
    {
        return $this->controller($orderService)->displayPage($request, $this->salesChannelContext($customerId));
    }

    private function pullStatus(OrderService $orderService, ?string $customerId): JsonResponse
    {
        return $this->controller($orderService)->pullStatus(
            new Request([], ['orderId' => self::ORDER_ID]),
            $this->salesChannelContext($customerId)
        );
    }

    private function controller(OrderService $orderService): IdealQrController
    {
        $controller = new class($orderService) extends IdealQrController {
            /**
             * @param array<string, mixed> $parameters
             */
            protected function renderStorefront(string $view, array $parameters = []): Response
            {
                return new JsonResponse(['view' => $view, 'parameters' => $parameters]);
            }
        };

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $container = new Container();
        $container->set('router', $router);
        $container->set('event_dispatcher', $this->createMock(EventDispatcherInterface::class));
        $controller->setContainer($container);

        return $controller;
    }

    private function orderService(OrderEntity $order): OrderService
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrderById')
            ->with(self::ORDER_ID, $this->isType('array'), $this->isInstanceOf(Context::class))
            ->willReturn($order);

        return $orderService;
    }

    private function order(string $customerId, ?string $qrImage, ?string $state = null): OrderEntity
    {
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setCustomerId($customerId);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $transaction->setUniqueIdentifier('transaction-id');
        if ($qrImage !== null) {
            $transaction->setCustomFields([IdealQrPaymentHandler::QR_IMAGE_URL_FIELD => $qrImage]);
        }
        if ($state !== null) {
            $stateMachineState = new StateMachineStateEntity();
            $stateMachineState->setTechnicalName($state);
            $transaction->setStateMachineState($stateMachineState);
        }

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setOrderCustomer($orderCustomer);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }

    private function salesChannelContext(?string $customerId): SalesChannelContext
    {
        $customer = null;
        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getCustomer')->willReturn($customer);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $salesChannelContext;
    }

    private function assertRedirectedToTheCart(Response $response): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/frontend.checkout.cart.page', $response->getTargetUrl());
    }
}
