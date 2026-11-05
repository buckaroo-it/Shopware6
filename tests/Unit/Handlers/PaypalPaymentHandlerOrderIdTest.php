<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Handlers\PaypalPaymentHandler;
use Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * The Store API route /store-api/handle-payment requires "orderId" (the Shopware order
 * id) and Shopware passes that request body on as the data bag. Only the PayPal Express
 * flow, which marks its bag with "paypalExpressInfo", carries a real PayPal order id.
 */
class PaypalPaymentHandlerOrderIdTest extends TestCase
{
    private const SHOPWARE_ORDER_ID = '01a1ab5bbe5c70f19c3f7814624d4e5a';

    private const PAYPAL_ORDER_ID = '6DE91051RL806624T';

    public function testStoreApiOrderIdIsNotSentAsPaypalOrderId(): void
    {
        $payload = $this->getHandler(false)->getMethodPayload(
            new OrderEntity(),
            new RequestDataBag(['orderId' => self::SHOPWARE_ORDER_ID]),
            $this->getSalesChannelContext(),
            'paypal'
        );

        $this->assertArrayNotHasKey('payPalOrderId', $payload);
    }

    public function testPaypalExpressOrderIdIsSentAsPaypalOrderId(): void
    {
        $payload = $this->getHandler(false)->getMethodPayload(
            new OrderEntity(),
            new RequestDataBag(['orderId' => self::PAYPAL_ORDER_ID, 'paypalExpressInfo' => true]),
            $this->getSalesChannelContext(),
            'paypal'
        );

        $this->assertSame(self::PAYPAL_ORDER_ID, $payload['payPalOrderId'] ?? null);
    }

    public function testStoreApiPaymentWithSellerProtectionUsesExtraInfo(): void
    {
        $action = $this->getHandler(true)->getMethodAction(
            new RequestDataBag(['orderId' => self::SHOPWARE_ORDER_ID]),
            $this->getSalesChannelContext()
        );

        $this->assertSame('extraInfo', $action);
    }

    public function testPaypalExpressWithSellerProtectionUsesPay(): void
    {
        $action = $this->getHandler(true)->getMethodAction(
            new RequestDataBag(['orderId' => self::PAYPAL_ORDER_ID, 'paypalExpressInfo' => true]),
            $this->getSalesChannelContext()
        );

        $this->assertSame('pay', $action);
    }

    private function getHandler(bool $sellerProtection): PaypalPaymentHandler
    {
        $settingsService = $this->createStub(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $key === 'paypalSellerprotection' ? $sellerProtection : null
        );

        $asyncPaymentService = $this->createStub(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->formatRequestParamService = $this->createStub(FormatRequestParamService::class);

        return new PaypalPaymentHandler(
            $asyncPaymentService,
            $this->createStub(UpdateOrderWithPaypalExpressData::class)
        );
    }

    private function getSalesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createStub(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn('sales-channel-id');

        return $salesChannelContext;
    }
}
