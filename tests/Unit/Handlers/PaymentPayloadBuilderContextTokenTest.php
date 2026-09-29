<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Handlers\PaymentFeeCalculator;
use Buckaroo\Shopware6\Handlers\PaymentPayloadBuilder;
use Buckaroo\Shopware6\Handlers\PaymentUrlGenerator;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Subscribers\PaymentContextCookieSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PaymentPayloadBuilderContextTokenTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const RETURN_URL = 'https://shop.test/payment/finalize-transaction?_sw_payment_token=jwt';

    public function testTheContextTokenNeverLeavesTheShop(): void
    {
        $request = new Request();
        $salesChannelContext = $this->createSalesChannelContext();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);

        $urlGenerator = $this->createMock(PaymentUrlGenerator::class);
        $urlGenerator->method('getDefaultReturnUrl')->willReturn('https://shop.test/checkout/finish');
        $urlGenerator->method('getPushUrl')->willReturn('https://shop.test/buckaroo/push');
        $urlGenerator->expects($this->once())
            ->method('getCancelRedirectUrlForOrder')
            ->with($this->anything(), $this->anything(), null, self::RETURN_URL)
            ->willReturn('https://shop.test/buckaroo/cancel');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('order-transaction-id');

        $payload = (new PaymentPayloadBuilder(
            $this->createAsyncPaymentService($request),
            $urlGenerator,
            $this->createMock(PaymentFeeCalculator::class)
        ))->buildCommonPayload(
            $orderTransaction,
            $this->createOrder(),
            new RequestDataBag(),
            $salesChannelContext,
            'ideal',
            self::RETURN_URL
        );

        $this->assertSame(self::RETURN_URL, $payload['returnURL']);
        $this->assertSame('https://shop.test/buckaroo/cancel', $payload['returnURLCancel']);
        $this->assertArrayNotHasKey('sw-context-token', $payload['additionalParameters']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($payload));

        // ...but the browser that started the payment receives it in the payment context cookie
        $response = new Response();
        (new PaymentContextCookieSubscriber())->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(self::TOKEN, $cookies[0]->getValue());
    }

    private function createAsyncPaymentService(Request $request): AsyncPaymentService
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getCurrentRequest')->willReturn($request);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getParsedLabel')->willReturn('Order 10001');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->method('getCurrency')->willReturn($currency);
        $asyncPaymentService->checkoutHelper = $checkoutHelper;
        $asyncPaymentService->settingsService = $settingsService;

        return $asyncPaymentService;
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getToken')->willReturn(self::TOKEN);
        $salesChannelContext->method('getSalesChannelId')->willReturn('sales-channel-id');
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $salesChannelContext;
    }

    private function createOrder(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setOrderNumber('10001');

        return $order;
    }
}
