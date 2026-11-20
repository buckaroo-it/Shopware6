<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\KlarnaMorService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\Service\RefundService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Storefront\Controller\CaptureController;
use Buckaroo\Shopware6\Storefront\Controller\KlarnaMorController;
use Buckaroo\Shopware6\Storefront\Controller\PaylinkController;
use Buckaroo\Shopware6\Storefront\Controller\RefundController;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\PhpNoticeGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Buckaroo order actions in the administration answer every failure with a
 * JSON error in the same format, using a generic message.
 */
class AdminActionControllerErrorResponseTest extends TestCase
{
    use PhpNoticeGuard;

    private const ORDER_ID = '0191c6b0e7d4705a8d6f2a6b3c4d5e6f';

    private const INTERNAL_DETAIL = 'internal detail /var/www/html/src/Service.php';

    /**
     * @dataProvider failureProvider
     */
    public function testRefundFailureReturnsGenericJsonError(\Throwable $failure): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('refundAll')->willThrowException($failure);

        $controller = new RefundController($refundService, $this->orderService(), $this->logger());

        $this->assertGenericError($this->call(
            $controller,
            fn () => $controller->refundBuckaroo(
                new Request([], ['transaction' => self::ORDER_ID, 'transactionsToRefund' => [['id' => 'trx-1']]]),
                Context::createDefaultContext()
            )
        ));
    }

    /**
     * @dataProvider failureProvider
     */
    public function testCaptureFailureReturnsGenericJsonError(\Throwable $failure): void
    {
        $captureService = $this->createMock(CaptureService::class);
        $captureService->method('capture')->willThrowException($failure);

        $controller = new CaptureController($captureService, $this->orderService(), $this->logger());

        $this->assertGenericError($this->call(
            $controller,
            fn () => $controller->captureBuckaroo(
                new Request([], ['transaction' => self::ORDER_ID]),
                Context::createDefaultContext()
            )
        ));
    }

    /**
     * @dataProvider failureProvider
     */
    public function testPaylinkFailureReturnsGenericJsonError(\Throwable $failure): void
    {
        $payLinkService = $this->createMock(PayLinkService::class);
        $payLinkService->method('create')->willThrowException($failure);

        $controller = new PaylinkController(
            $payLinkService,
            $this->orderService(),
            $this->logger(),
            $this->createMock(SignatureValidationService::class)
        );

        $this->assertGenericError($this->call(
            $controller,
            fn () => $controller->paylinkBuckaroo(
                new Request([], ['transaction' => self::ORDER_ID]),
                Context::createDefaultContext()
            )
        ));
    }

    /**
     * @dataProvider failureProvider
     */
    public function testKlarnaFailureReturnsGenericJsonError(\Throwable $failure): void
    {
        $klarnaMorService = $this->createMock(KlarnaMorService::class);
        $klarnaMorService->method('execute')->willThrowException($failure);

        $controller = new KlarnaMorController($klarnaMorService, $this->orderService(), $this->logger());

        $this->assertGenericError($this->call(
            $controller,
            fn () => $controller->action(
                new Request([], ['orderId' => self::ORDER_ID, 'action' => 'pay']),
                Context::createDefaultContext()
            )
        ));
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function failureProvider(): array
    {
        return [
            'type error' => [new \TypeError(self::INTERNAL_DETAIL)],
            'error' => [new \Error(self::INTERNAL_DETAIL)],
            'exception' => [new \RuntimeException(self::INTERNAL_DETAIL)],
        ];
    }

    public function testRefundValidationResultIsReturnedAsIs(): void
    {
        $validation = [['status' => false, 'message' => 'buckaroo-payment.paylink.invalid_amount']];

        $refundService = $this->createMock(RefundService::class);
        $refundService->method('refundAll')->willReturn($validation);

        $controller = new RefundController(
            $refundService,
            $this->orderService(),
            $this->createMock(LoggerInterface::class)
        );

        $response = $this->call(
            $controller,
            fn () => $controller->refundBuckaroo(
                new Request([], ['transaction' => self::ORDER_ID, 'transactionsToRefund' => [['id' => 'trx-1']]]),
                Context::createDefaultContext()
            )
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame($validation, json_decode((string)$response->getContent(), true));
    }

    public function testRefundWithoutTransactionsIsRejectedBeforeRefunding(): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->never())->method('refundAll');

        $controller = new RefundController(
            $refundService,
            $this->orderService(),
            $this->createMock(LoggerInterface::class)
        );

        $response = $this->call(
            $controller,
            fn () => $controller->refundBuckaroo(
                new Request([], ['transaction' => self::ORDER_ID, 'transactionsToRefund' => 'invalid']),
                Context::createDefaultContext()
            )
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.cannotRefundMissingPayment'],
            json_decode((string)$response->getContent(), true)
        );
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function call(StorefrontController $controller, callable $action): JsonResponse
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        return $this->withoutPhpNotices($action);
    }

    private function assertGenericError(JsonResponse $response): void
    {
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringNotContainsString('internal detail', (string)$response->getContent());
        $this->assertSame(
            [
                'status' => false,
                'message' => 'buckaroo-payment.general_request_error',
                'code' => 0,
            ],
            json_decode((string)$response->getContent(), true)
        );
    }

    private function orderService(): OrderService
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrderById')->willReturn($order);

        return $orderService;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug');

        return $logger;
    }
}
