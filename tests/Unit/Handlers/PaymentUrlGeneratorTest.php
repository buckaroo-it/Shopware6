<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Handlers\PaymentUrlGenerator;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * The checkout request may carry a finishUrl that becomes the URL the shopper is sent to
 * after paying. It used to be used verbatim when absolute, which let a crafted checkout
 * request send the shopper to a foreign host and skip Shopware's token protected finalize
 * route. Only a path on the shop's own host is used now.
 */
class PaymentUrlGeneratorTest extends TestCase
{
    private const SHOP_URL = 'https://shop.test';

    private const FINISH_PAGE = 'https://shop.test/checkout/finish?orderId=order-id';

    /**
     * @dataProvider foreignFinishUrlProvider
     */
    public function testAFinishUrlOffTheShopHostIsIgnored(string $finishUrl): void
    {
        $url = $this->generator()->getReturnUrl(
            $this->orderTransaction(),
            $this->order(),
            new RequestDataBag(['finishUrl' => $finishUrl])
        );

        $this->assertSame(self::FINISH_PAGE, $url);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function foreignFinishUrlProvider(): array
    {
        return [
            'https' => ['https://attacker.test/'],
            'http' => ['http://attacker.test/finish'],
            'protocol relative' => ['//attacker.test/finish'],
            'backslash protocol relative' => ['/\\attacker.test/finish'],
            'scheme' => ['javascript:alert(1)'],
            'relative without slash' => ['checkout/finish'],
            'empty' => [''],
        ];
    }

    public function testAShopPathIsAppendedToTheShopUrl(): void
    {
        $url = $this->generator()->getReturnUrl(
            $this->orderTransaction(),
            $this->order(),
            new RequestDataBag(['finishUrl' => '/checkout/finish?orderId=order-id'])
        );

        $this->assertSame(self::FINISH_PAGE, $url);
    }

    public function testWithoutAFinishUrlTheFinishPageIsUsed(): void
    {
        $url = $this->generator()->getReturnUrl($this->orderTransaction(), $this->order(), new RequestDataBag());

        $this->assertSame(self::FINISH_PAGE, $url);
    }

    private function generator(): PaymentUrlGenerator
    {
        $urlService = $this->createMock(UrlService::class);
        $urlService->method('forwardToRoute')
            ->with('frontend.home.page', [])
            ->willReturn(self::SHOP_URL . '/');
        $urlService->method('generateAbsoluteUrl')
            ->with('frontend.checkout.finish.page', ['orderId' => 'order-id'])
            ->willReturn(self::FINISH_PAGE);

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->urlService = $urlService;

        return new PaymentUrlGenerator($asyncPaymentService);
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');

        return $order;
    }

    private function orderTransaction(): OrderTransactionEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');

        return $orderTransaction;
    }
}
