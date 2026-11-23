<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Handlers\PaymentHandlerLegacy;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\UrlService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Shopware 6.5 (and 6.6 before AbstractPaymentHandler) handler line: the order is marked
 * as paid in test mode by the successful push, the same way on every Shopware version,
 * and no longer by the response to the payment request.
 */
class PaymentHandlerLegacyTestModeMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        if (
            class_exists('Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')
            || !interface_exists(
                'Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface'
            )
        ) {
            $this->markTestSkipped('The legacy pay() path only runs on Shopware without AbstractPaymentHandler');
        }
    }

    /**
     * @dataProvider responsesWithTestFlag
     */
    public function testPaymentResponseDoesNotWriteTheTestModeMarker(bool $hasRedirect): void
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->never())->method('appendCustomFields');
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->expects($this->once())
            ->method('updateTransactionCustomFields')
            ->with('transaction-id', ['originalTransactionKey' => 'key-123'], $this->isInstanceOf(Context::class));

        $urlService = $this->createMock(UrlService::class);
        $urlService->method('generateAbsoluteUrl')->willReturn('https://shop.test/checkout/finish');

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->checkoutHelper = $checkoutHelper;
        $asyncPaymentService->transactionService = $transactionService;
        $asyncPaymentService->urlService = $urlService;
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isTestMode')->willReturn(true);
        $response->method('isSuccess')->willReturn(!$hasRedirect);
        $response->method('hasRedirect')->willReturn($hasRedirect);
        $response->method('getRedirectUrl')->willReturn('https://checkout.buckaroo.test/redirect');
        $response->method('getTransactionKey')->willReturn('key-123');

        $handler = new class ($asyncPaymentService) extends PaymentHandlerLegacy {
            public function callHandleResponse(
                ClientResponseInterface $response,
                object $transaction,
                SalesChannelContext $salesChannelContext
            ): RedirectResponse {
                /** @phpstan-ignore-next-line the struct only exists on the legacy Shopware line */
                return $this->handleResponse(
                    $response,
                    $transaction,
                    new RequestDataBag(),
                    $salesChannelContext,
                    'ideal'
                );
            }
        };

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $handler->callHandleResponse($response, $this->transaction(), $salesChannelContext);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function responsesWithTestFlag(): array
    {
        return [
            'redirect to the payment page' => [true],
            'paid right away' => [false],
        ];
    }

    private function transaction(): object
    {
        $order = new OrderEntity();
        $order->setId('order-id');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setOrder($order);

        $structClass = 'Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct';

        return new $structClass($orderTransaction, $order, 'https://shop.test/return');
    }
}
