<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The duplicate push guard hashes the parameters of the push it is handling. Those used
 * to come from $_POST; they now come from the Symfony request the webhook was routed
 * with, which is the same set of form parameters the signature validation already reads.
 */
class PushControllerDuplicatePushTest extends TestCase
{
    public function testDuplicatePushHashIsCalculatedFromTheRequestPostParameters(): void
    {
        $postData = [
            'brq_amount' => '10.00',
            'brq_invoicenumber' => 'INV-001',
            'brq_statuscode' => '190',
        ];

        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->expects($this->once())
            ->method('calculatePushHash')
            ->with($this->identicalTo($postData))
            ->willReturn('calculated-hash');

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('getCustomFields')->willReturn([]);
        $transactionService->expects($this->once())
            ->method('updateTransactionCustomFields')
            ->with('order-transaction-id', ['pushHash' => 'calculated-hash']);

        $request = new Request(['brq_amount' => 'from-query'], $postData);

        $this->assertTrue(
            $this->invokeCheckDuplicatePush(
                $this->createController($signatureValidationService, $transactionService),
                $request
            ),
            'a push with an unseen hash must not be treated as a duplicate'
        );
    }

    public function testPushWithTheStoredHashIsTreatedAsDuplicate(): void
    {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('calculatePushHash')->willReturn('stored-hash');

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('getCustomFields')->willReturn(['pushHash' => 'stored-hash']);

        $this->assertFalse(
            $this->invokeCheckDuplicatePush(
                $this->createController($signatureValidationService, $transactionService),
                new Request([], ['brq_amount' => '10.00'])
            )
        );
    }

    private function invokeCheckDuplicatePush(PushController $controller, Request $request): bool
    {
        $method = new \ReflectionMethod(PushController::class, 'checkDuplicatePush');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke(
            $controller,
            $request,
            new OrderEntity(),
            'order-transaction-id',
            Context::createDefaultContext()
        );

        return $result;
    }

    private function createController(
        SignatureValidationService $signatureValidationService,
        TransactionService $transactionService
    ): PushController {
        return new PushController(
            $signatureValidationService,
            $transactionService,
            $this->createMock(StateTransitionService::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(CheckoutHelper::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(IdealQrOrderRepository::class),
            $this->createMock(OrderService::class),
            $this->createMock(CustomerService::class)
        );
    }
}
