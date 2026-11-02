<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Storefront\Controller\PaylinkController;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The PayPerEmail return page names the order and links to it through its deep link
 * code, the secret that otherwise only travels in the order e-mail. It used to be shown
 * for any order id in the request, so these tests pin that it is only shown for a return
 * Buckaroo signed for that very order, in the sales channel the order belongs to.
 */
class PaylinkControllerReturnTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    private const ORDER_ID = '0192d1e2f3a4b5c6d7e8f9a0b1c2d3e4';

    private const ORDER_NUMBER = '10001';

    private const HOME_URL = '/home';

    public function testAnUnsignedReturnIsSentHomeWithoutLoadingTheOrder(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('getOrderById');

        $response = $this->handleReturn(
            new Request(['orderId' => self::ORDER_ID, 'brq_statuscode' => '190']),
            $this->signatureService(false),
            $orderService
        );

        $this->assertRedirectedHome($response);
    }

    public function testAReturnSignedForAnotherOrderIsSentHome(): void
    {
        $response = $this->handleReturn(
            new Request(['orderId' => self::ORDER_ID]),
            $this->signatureService(true, [
                'ADD_orderId' => 'another-order-id',
                'brq_invoicenumber' => '10099',
                'brq_statuscode' => '190',
            ]),
            $this->orderService($this->order())
        );

        $this->assertRedirectedHome($response);
    }

    public function testAReturnForAnOrderOfAnotherSalesChannelIsSentHome(): void
    {
        $order = $this->order();
        $order->setSalesChannelId('another-sales-channel-id');

        $response = $this->handleReturn(
            new Request(['orderId' => self::ORDER_ID]),
            $this->signatureService(true, ['ADD_orderId' => self::ORDER_ID, 'brq_statuscode' => '190']),
            $this->orderService($order)
        );

        $this->assertRedirectedHome($response);
    }

    /**
     * Buckaroo does not keep the casing of the additional parameter names it echoes back.
     */
    public function testAReturnSignedForTheOrderShowsTheConfirmationWithTheDeepLink(): void
    {
        $response = $this->handleReturn(
            new Request([], ['orderId' => self::ORDER_ID]),
            $this->signatureService(true, [
                'brq_additionalparameters_orderid' => strtoupper(self::ORDER_ID),
                'brq_statuscode' => '190',
            ]),
            $this->orderService($this->order())
        );

        $this->assertRendered(
            $response,
            '@BuckarooPayments/storefront/buckaroo/payperemail-confirmation.html.twig',
            ['orderNumber' => self::ORDER_NUMBER, 'orderDeepLink' => 'deep-link-code']
        );
    }

    public function testAReturnSignedWithTheOrderNumberShowsTheConfirmation(): void
    {
        $response = $this->handleReturn(
            new Request(['orderId' => self::ORDER_ID]),
            $this->signatureService(true, ['brq_invoicenumber' => self::ORDER_NUMBER, 'brq_statuscode' => '190']),
            $this->orderService($this->order())
        );

        $this->assertRendered(
            $response,
            '@BuckarooPayments/storefront/buckaroo/payperemail-confirmation.html.twig',
            ['orderNumber' => self::ORDER_NUMBER, 'orderDeepLink' => 'deep-link-code']
        );
    }

    /**
     * The status code deciding between the thank you and the cancel page is the signed one.
     */
    public function testACancelledReturnShowsTheCancelPageWithoutTheDeepLink(): void
    {
        $response = $this->handleReturn(
            new Request(['orderId' => self::ORDER_ID, 'brq_statuscode' => '190']),
            $this->signatureService(true, ['ADD_orderId' => self::ORDER_ID, 'brq_statuscode' => '890']),
            $this->orderService($this->order())
        );

        $this->assertRendered(
            $response,
            '@BuckarooPayments/storefront/buckaroo/payperemail-cancelled.html.twig',
            ['orderNumber' => self::ORDER_NUMBER]
        );
    }

    private function handleReturn(
        Request $request,
        SignatureValidationService $signatureValidationService,
        OrderService $orderService
    ): Response {
        $controller = new class(
            $this->createMock(PayLinkService::class),
            $orderService,
            $this->createMock(LoggerInterface::class),
            $signatureValidationService
        ) extends PaylinkController {
            /**
             * @param array<string, mixed> $parameters
             */
            protected function renderStorefront(string $view, array $parameters = []): Response
            {
                unset($parameters['order']);

                return new JsonResponse(['view' => $view, 'parameters' => $parameters]);
            }
        };

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('frontend.home.page')->willReturn(self::HOME_URL);

        $container = new Container();
        $container->set('router', $router);
        $container->set('event_dispatcher', $this->createMock(EventDispatcherInterface::class));
        $controller->setContainer($container);

        return $controller->payPerEmailReturn($request, $this->salesChannelContext());
    }

    /**
     * @param array<string, string> $signedData
     */
    private function signatureService(bool $valid, array $signedData = []): SignatureValidationService
    {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateReturnSignature')
            ->with($this->isInstanceOf(Request::class), self::SALES_CHANNEL_ID)
            ->willReturn($valid);
        $signatureValidationService->method('getSignedReturnData')->willReturn($signedData);

        return $signatureValidationService;
    }

    private function orderService(OrderEntity $order): OrderService
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrderById')
            ->with(self::ORDER_ID, ['orderCustomer'], $this->isInstanceOf(Context::class))
            ->willReturn($order);

        return $orderService;
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setOrderNumber(self::ORDER_NUMBER);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setDeepLinkCode('deep-link-code');

        return $order;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $salesChannelContext;
    }

    private function assertRedirectedHome(Response $response): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::HOME_URL, $response->getTargetUrl());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function assertRendered(Response $response, string $view, array $parameters): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(
            ['view' => $view, 'parameters' => $parameters],
            json_decode((string)$response->getContent(), true)
        );
    }
}
