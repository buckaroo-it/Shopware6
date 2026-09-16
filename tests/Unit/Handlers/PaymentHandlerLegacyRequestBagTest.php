<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Handlers\PaymentHandlerLegacy;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * The order edit flow ("/account/order/edit/") needs the parameters of the request that
 * is currently being handled, not the ones of the bag the payment handler was called
 * with. Those used to be read from $_POST/$_GET; they now come from the Symfony request,
 * which is what makes the handler testable and proxy safe.
 *
 * PaymentHandlerLegacy implements AsynchronousPaymentHandlerInterface, which Shopware
 * dropped in 6.7, so these tests only run on the 6.5/6.6 line the handler serves.
 */
class PaymentHandlerLegacyRequestBagTest extends TestCase
{
    /**
     * @var string
     */
    private const EDIT_ERROR_URL = '/account/order/edit/0189f0e0e0e0';

    protected function setUp(): void
    {
        if (!interface_exists(
            'Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface'
        )) {
            $this->markTestSkipped('PaymentHandlerLegacy is only loadable on Shopware < 6.7');
        }
    }

    public function testUpdateOrderReadsParametersFromTheCurrentRequest(): void
    {
        $bag = $this->getRequestBag(
            new Request(
                ['queryOnly' => 'ignored'],
                ['errorUrl' => self::EDIT_ERROR_URL, 'paymentMethodId' => 'from-request']
            ),
            new RequestDataBag(['errorUrl' => self::EDIT_ERROR_URL, 'paymentMethodId' => 'from-bag'])
        );

        $this->assertSame('from-request', $bag->get('paymentMethodId'));
        $this->assertFalse($bag->has('queryOnly'), 'query parameters must not leak into the post bag');
    }

    public function testNonUpdateOrderKeepsTheOriginalBag(): void
    {
        $bag = $this->getRequestBag(
            new Request([], ['errorUrl' => '/checkout/confirm', 'paymentMethodId' => 'from-request']),
            new RequestDataBag(['errorUrl' => '/checkout/confirm', 'paymentMethodId' => 'from-bag'])
        );

        $this->assertSame('from-bag', $bag->get('paymentMethodId'));
    }

    /**
     * Outside an HTTP request (CLI, message queue worker) there is nothing to read from,
     * so the bag the handler was called with stays in use.
     */
    public function testWithoutACurrentRequestTheOriginalBagIsKept(): void
    {
        $bag = $this->getRequestBag(
            null,
            new RequestDataBag(['errorUrl' => self::EDIT_ERROR_URL, 'paymentMethodId' => 'from-bag'])
        );

        $this->assertSame('from-bag', $bag->get('paymentMethodId'));
    }

    private function getRequestBag(?Request $currentRequest, RequestDataBag $currentBag): RequestDataBag
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getCurrentRequest')->willReturn($currentRequest);

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->checkoutHelper = $checkoutHelper;
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);

        // Anonymous so the subclass is only declared once the parent class can be loaded.
        $handler = new class ($asyncPaymentService) extends PaymentHandlerLegacy {
            public function exposeGetRequestBag(RequestDataBag $currentBag): RequestDataBag
            {
                return $this->getRequestBag($currentBag);
            }
        };

        return $handler->exposeGetRequestBag($currentBag);
    }
}
