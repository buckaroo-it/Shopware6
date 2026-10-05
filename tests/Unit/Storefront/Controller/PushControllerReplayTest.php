<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Events\PushProcessingEvent;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A push is recorded on its order transaction by its Buckaroo transaction key and status
 * before anything acts on it, and a push that was already processed is not processed again.
 */
class PushControllerReplayTest extends TestCase
{
    private const TRANSACTION_ID = 'order-transaction-id';

    public function testReplayedAuthorizePushDoesNotAuthorizeAgain(): void
    {
        $postData = $this->pushData(['brq_transaction_type' => 'I872']);

        $response = $this->handlePush($postData, [
            'transactionService' => $this->transactionServiceWithClaims([$this->claimOf($postData)]),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    public function testReplayedFastCheckoutPushDoesNotUpdateTheCustomer(): void
    {
        $postData = $this->pushData(['brq_SERVICE_ideal_TransactionFlow' => 'Fast_Checkout']);

        $response = $this->handlePush($postData, [
            'transactionService' => $this->transactionServiceWithClaims([$this->claimOf($postData)]),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    /**
     * An earlier pending status, or an informational push, of the same transaction does
     * not block it: only an identical push counts as already processed.
     */
    public function testNewPushOfAKnownTransactionIsClaimedAndProcessed(): void
    {
        $earlierClaims = ['KEY-123|791|I872|PROCESSING', 'KEY-123|190|I872|INFORMATIONAL'];

        $transactionService = $this->transactionServiceWithClaims($earlierClaims);
        $transactionService->expects($this->once())
            ->method('updateTransactionCustomFields')
            ->with(self::TRANSACTION_ID, [
                PushController::PROCESSED_PUSHES_FIELD => [...$earlierClaims, 'KEY-123|190|I872|PROCESSING'],
            ]);

        $stateTransitionService = $this->createMock(StateTransitionService::class);
        $stateTransitionService->expects($this->once())->method('isTransitionPaymentState')->willReturn(true);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls($this->order(), null);

        $response = $this->handlePush($this->pushData(['brq_transaction_type' => 'I872']), [
            'transactionService' => $transactionService,
            'stateTransitionService' => $stateTransitionService,
            'checkoutHelper' => $checkoutHelper,
            'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
        ]);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * A push whose processing throws answers Buckaroo with an error, so Buckaroo retries
     * it. The claim is released, otherwise the retry would be ignored.
     */
    public function testClaimIsReleasedWhenProcessingThrows(): void
    {
        $transactionService = $this->transactionServiceWithClaims(['KEY-OTHER|190|C021|PROCESSING']);
        $transactionService->expects($this->once())->method('updateTransactionCustomFields');
        $transactionService->expects($this->once())
            ->method('saveTransactionData')
            ->with(
                self::TRANSACTION_ID,
                $this->isInstanceOf(Context::class),
                [PushController::PROCESSED_PUSHES_FIELD => ['KEY-OTHER|190|C021|PROCESSING']]
            );

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls(
            $this->order(),
            $this->throwException(new \RuntimeException('database gone'))
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database gone');

        $this->handlePush($this->pushData(), [
            'transactionService' => $transactionService,
            'checkoutHelper' => $checkoutHelper,
            'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
        ]);
    }

    /**
     * A subscriber interrupting the push takes it out of normal processing; the same push
     * arriving again must be handled as before, not ignored.
     */
    public function testClaimIsReleasedWhenAPushProcessingSubscriberInterruptsThePush(): void
    {
        $transactionService = $this->transactionServiceWithClaims([]);
        $transactionService->expects($this->once())
            ->method('saveTransactionData')
            ->with(
                self::TRANSACTION_ID,
                $this->isInstanceOf(Context::class),
                [PushController::PROCESSED_PUSHES_FIELD => []]
            );

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static fn (PushProcessingEvent $event): object => $event->setCanContinue(false)
        );

        $response = $this->handlePush($this->pushData(), [
            'transactionService' => $transactionService,
            'eventDispatcher' => $eventDispatcher,
        ]);

        $this->assertSame(
            ['status' => true, 'message' => 'buckaroo.messages.pushInterrupted'],
            $this->decode($response)
        );
    }

    private function assertAlreadyProcessed(JsonResponse $response): void
    {
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.pushAlreadySend'],
            $this->decode($response),
            'an already processed push must be rejected before anything acts on it'
        );
    }

    /**
     * @param array<int, string> $claims
     *
     * @return TransactionService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function transactionServiceWithClaims(array $claims)
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setCustomFields([PushController::PROCESSED_PUSHES_FIELD => $claims]);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('getOrderTransactionById')->willReturn($orderTransaction);

        return $transactionService;
    }

    /**
     * @param array<string, string> $postData
     */
    private function claimOf(array $postData): string
    {
        return strtoupper(implode('|', [
            $postData['brq_transactions'],
            $postData['brq_statuscode'],
            $postData['brq_transaction_type'] ?? '',
            $postData['brq_mutationtype'] ?? '',
        ]));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function pushData(array $overrides = []): array
    {
        return $overrides + [
            'brq_statuscode' => '190',
            'brq_amount' => '10.00',
            'brq_invoicenumber' => 'INV-001',
            'brq_transaction_method' => 'ideal',
            'brq_transaction_type' => 'C021',
            'brq_mutationtype' => 'Processing',
            'brq_transactions' => 'key-123',
            'ADD_orderId' => 'order-id',
            'ADD_orderTransactionId' => self::TRANSACTION_ID,
        ];
    }

    /**
     * Run the push action with a valid signature. Every collaborator not passed in fails
     * the test as soon as it is called.
     *
     * @param array<string, string> $postData
     * @param array<string, object> $services
     */
    private function handlePush(array $postData, array $services = []): JsonResponse
    {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateSignature')->willReturn(true);

        /** @var TransactionService $transactionService */
        $transactionService = $services['transactionService'] ?? $this->untouchable(TransactionService::class);
        /** @var StateTransitionService $stateTransitionService */
        $stateTransitionService = $services['stateTransitionService']
            ?? $this->untouchable(StateTransitionService::class);
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $services['checkoutHelper'] ?? $this->checkoutHelperFindingOnlyTheOrder();
        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $services['eventDispatcher'] ?? $this->untouchable(EventDispatcherInterface::class);

        $controller = new PushController(
            $signatureValidationService,
            $transactionService,
            $stateTransitionService,
            $this->untouchable(InvoiceService::class),
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $eventDispatcher,
            $this->untouchable(IdealQrOrderRepository::class),
            $this->untouchable(OrderService::class),
            $this->untouchable(CustomerService::class)
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn('sales-channel-id');
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $controller->pushBuckaroo(new Request([], $postData), $salesChannelContext);
    }

    /**
     * The push is bound to its order before it is claimed; nothing else may be called.
     *
     * @return CheckoutHelper&\PHPUnit\Framework\MockObject\MockObject
     */
    private function checkoutHelperFindingOnlyTheOrder()
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturn($this->order());
        $checkoutHelper->expects($this->never())
            ->method($this->logicalNot($this->equalTo('getOrderById')));

        return $checkoutHelper;
    }

    private function order(): OrderEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId('sales-channel-id');
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        return $order;
    }

    /**
     * @param class-string $className
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function untouchable(string $className)
    {
        $mock = $this->createMock($className);
        $mock->expects($this->never())->method($this->anything());

        return $mock;
    }

    /**
     * @return array<mixed>
     */
    private function decode(JsonResponse $response): array
    {
        /** @var array<mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded;
    }
}
