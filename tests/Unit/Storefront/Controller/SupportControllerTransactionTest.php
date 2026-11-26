<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Service\BuckarooTransactionService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\TestCredentialsService;
use Buckaroo\Shopware6\Storefront\Controller\SupportController;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\PhpNoticeGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class SupportControllerTransactionTest extends TestCase
{
    use PhpNoticeGuard;

    private const ORDER_ID = '0191c6b0e7d4705a8d6f2a6b3c4d5e6f';

    /**
     * @dataProvider missingOrderIdProvider
     *
     * @param array<string, mixed> $body
     */
    public function testMissingOrderIdReturnsJsonError(array $body): void
    {
        $transactionService = $this->createMock(BuckarooTransactionService::class);
        $transactionService->expects($this->never())->method('getBuckarooTransactionsByOrderId');

        $response = $this->getTransaction($transactionService, new Request([], $body));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo-payment.missing_order_id'],
            json_decode((string)$response->getContent(), true)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingOrderIdProvider(): array
    {
        return [
            'no order id' => [[]],
            'empty order id' => [['transaction' => '']],
            'order id not a string' => [['transaction' => ['id' => self::ORDER_ID]]],
        ];
    }

    public function testTransactionsAreReturnedForOrderId(): void
    {
        $transactions = ['transactions' => [], 'orderItems' => []];

        $transactionService = $this->createMock(BuckarooTransactionService::class);
        $transactionService->expects($this->once())
            ->method('getBuckarooTransactionsByOrderId')
            ->with(self::ORDER_ID, $this->isInstanceOf(Context::class))
            ->willReturn($transactions);

        $response = $this->getTransaction(
            $transactionService,
            new Request([], ['transaction' => self::ORDER_ID])
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame($transactions, json_decode((string)$response->getContent(), true));
    }

    private function getTransaction(BuckarooTransactionService $transactionService, Request $request): JsonResponse
    {
        $controller = new SupportController(
            $this->createMock(TestCredentialsService::class),
            $transactionService,
            $this->createMock(EntityRepository::class),
            $this->createMock(In3LogoService::class),
            '6.7.0.0'
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        return $this->withoutPhpNotices(
            fn () => $controller->getBuckarooTransaction($request, Context::createDefaultContext())
        );
    }
}
