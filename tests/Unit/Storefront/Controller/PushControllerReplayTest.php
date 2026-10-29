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
use Buckaroo\Shopware6\Service\ProcessedPushService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A push is claimed by its Buckaroo transaction key, status, transaction type and mutation
 * type before anything acts on it, and a push that was already claimed is not processed
 * again.
 */
class PushControllerReplayTest extends TestCase
{
    private const TRANSACTION_ID = 'order-transaction-id';

    private const PUSH_KEY = 'KEY-123|190|C021|PROCESSING';

    public function testReplayedAuthorizePushDoesNotAuthorizeAgain(): void
    {
        $response = $this->handlePush($this->pushData(['brq_transaction_type' => 'I872']), [
            'processedPushService' => $this->processedPushService(false, 'KEY-123|190|I872|PROCESSING'),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    public function testReplayedFastCheckoutPushDoesNotUpdateTheCustomer(): void
    {
        $response = $this->handlePush($this->pushData(['brq_SERVICE_ideal_TransactionFlow' => 'Fast_Checkout']), [
            'processedPushService' => $this->processedPushService(claimed: false),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    /**
     * Of two concurrent copies of the same push only the one that wins the claim is
     * processed; the other is answered as already processed.
     */
    public function testSecondCopyOfAPushIsNotProcessed(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->exactly(2))
            ->method('claim')
            ->with(self::PUSH_KEY, self::TRANSACTION_ID)
            ->willReturnOnConsecutiveCalls(true, false);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls($this->order(), null, $this->order());

        $services = [
            'processedPushService' => $processedPushService,
            'checkoutHelper' => $checkoutHelper,
            'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
        ];

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($this->handlePush($this->pushData(), $services)),
            'the first copy is processed (and ends here because the order is no longer found)'
        );
        $this->assertAlreadyProcessed($this->handlePush($this->pushData(), $services));
    }

    /**
     * Earlier plugin versions recorded processed pushes on the order transaction. Those
     * pushes must not be processed again after an update.
     */
    public function testPushRecordedByAnEarlierPluginVersionIsNotProcessedAgain(): void
    {
        $response = $this->handlePush($this->pushData(), [
            'checkoutHelper' => $this->checkoutHelperFindingOnlyTheOrder([self::PUSH_KEY]),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    /**
     * The claim is keyed on the values as they were signed: an encoded variant of a signed
     * value is the same push.
     */
    public function testClaimIsKeyedOnTheSignedValues(): void
    {
        $postData = $this->pushData(['brq_transactions' => '%6Bey-123', 'brq_mutationtype' => '%50rocessing']);

        $response = $this->handlePush($postData, [
            'processedPushService' => $this->processedPushService(claimed: false),
            'signedPushData' => $this->pushData(),
        ]);

        $this->assertAlreadyProcessed($response);
    }

    /**
     * A data request push (Klarna MoR reserve) carries its data request key instead of a
     * transaction key, and is claimed by it.
     */
    public function testDataRequestPushIsClaimedByItsDataRequestKey(): void
    {
        $postData = $this->pushData(['brq_datarequest' => 'drk-456', 'brq_mutationtype' => 'Informational']);
        unset($postData['brq_transactions'], $postData['brq_transaction_type']);

        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with('DRK-456|190||INFORMATIONAL', self::TRANSACTION_ID)
            ->willReturn(false);

        $this->assertAlreadyProcessed($this->handlePush($postData, ['processedPushService' => $processedPushService]));
    }

    /**
     * A Klarna MoR reserve push may carry its data request key only in the Klarna service
     * field or in brq_datarequest_. It must still be accepted, otherwise the key is never
     * stored and the capture fails with "Parameter 'DataRequestKey' is empty".
     *
     * @dataProvider klarnaDataRequestKeyFieldProvider
     */
    public function testKlarnaReservePushIsClaimedByItsDataRequestKeyField(string $field): void
    {
        $postData = $this->pushData([
            $field => 'drk-789',
            'brq_primary_service' => 'klarna',
            'brq_mutationtype' => 'Processing',
        ]);
        unset($postData['brq_transactions'], $postData['brq_transaction_type']);

        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with('DRK-789|190||PROCESSING', self::TRANSACTION_ID)
            ->willReturn(false);

        $this->assertAlreadyProcessed($this->handlePush($postData, ['processedPushService' => $processedPushService]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function klarnaDataRequestKeyFieldProvider(): array
    {
        return [
            'klarna service data request key' => ['brq_SERVICE_klarna_DataRequestKey'],
            'data request key with trailing underscore' => ['brq_datarequest_'],
        ];
    }

    /**
     * A push that cannot be identified cannot be claimed, so it is rejected before
     * anything is looked up or changed.
     *
     * @dataProvider unidentifiablePushProvider
     *
     * @param array<int, string> $missingFields
     */
    public function testPushWithoutTransactionKeyOrStatusIsRejected(array $missingFields): void
    {
        $postData = $this->pushData();
        foreach ($missingFields as $field) {
            unset($postData[$field]);
        }

        $response = $this->handlePush($postData, [
            'checkoutHelper' => $this->untouchable(CheckoutHelper::class),
        ]);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * @return array<string, array{array<int, string>}>
     */
    public static function unidentifiablePushProvider(): array
    {
        return [
            'no transaction key' => [['brq_transactions']],
            'no status code' => [['brq_statuscode']],
        ];
    }

    /**
     * An earlier status, or an informational push, of the same transaction does not block
     * it: only an identical push counts as already processed.
     */
    public function testNewPushOfAKnownTransactionIsClaimedAndProcessed(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with('KEY-123|190|I872|PROCESSING', self::TRANSACTION_ID)
            ->willReturn(true);

        $stateTransitionService = $this->createMock(StateTransitionService::class);
        $stateTransitionService->expects($this->once())->method('isTransitionPaymentState')->willReturn(true);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls(
            $this->order(['KEY-123|791|I872|PROCESSING', 'KEY-123|190|I872|INFORMATIONAL']),
            null
        );

        $response = $this->handlePush($this->pushData(['brq_transaction_type' => 'I872']), [
            'processedPushService' => $processedPushService,
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
        $processedPushService = $this->processedPushService(claimed: true);
        $processedPushService->expects($this->once())->method('release')->with(self::PUSH_KEY);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls(
            $this->order(),
            $this->throwException(new \RuntimeException('database gone'))
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database gone');

        $this->handlePush($this->pushData(), [
            'processedPushService' => $processedPushService,
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
        $processedPushService = $this->processedPushService(claimed: true);
        $processedPushService->expects($this->once())->method('release')->with(self::PUSH_KEY);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static fn (PushProcessingEvent $event): object => $event->setCanContinue(false)
        );

        $response = $this->handlePush($this->pushData(), [
            'processedPushService' => $processedPushService,
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
     * @return ProcessedPushService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function processedPushService(bool $claimed, string $pushKey = self::PUSH_KEY)
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with($pushKey, self::TRANSACTION_ID)
            ->willReturn($claimed);

        return $processedPushService;
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
     * @param array<string, mixed> $services 'signedPushData' overrides the values the
     *                                       signature was validated over
     */
    private function handlePush(array $postData, array $services = []): JsonResponse
    {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateSignature')->willReturn(true);
        $signatureValidationService->method('getSignedPushData')
            ->willReturn($services['signedPushData'] ?? $postData);

        /** @var StateTransitionService $stateTransitionService */
        $stateTransitionService = $services['stateTransitionService']
            ?? $this->untouchable(StateTransitionService::class);
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $services['checkoutHelper'] ?? $this->checkoutHelperFindingOnlyTheOrder();
        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $services['eventDispatcher'] ?? $this->untouchable(EventDispatcherInterface::class);
        /** @var ProcessedPushService $processedPushService */
        $processedPushService = $services['processedPushService']
            ?? $this->untouchable(ProcessedPushService::class);

        $controller = new PushController(
            $signatureValidationService,
            $this->untouchable(TransactionService::class),
            $stateTransitionService,
            $this->untouchable(InvoiceService::class),
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $eventDispatcher,
            $this->untouchable(IdealQrOrderRepository::class),
            $this->untouchable(OrderService::class),
            $this->untouchable(CustomerService::class),
            $processedPushService,
            $this->untouchable(SalesChannelContextServiceInterface::class)
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
     * @param array<int, string> $legacyClaims
     *
     * @return CheckoutHelper&\PHPUnit\Framework\MockObject\MockObject
     */
    private function checkoutHelperFindingOnlyTheOrder(array $legacyClaims = [])
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturn($this->order($legacyClaims));
        $checkoutHelper->expects($this->never())
            ->method($this->logicalNot($this->equalTo('getOrderById')));

        return $checkoutHelper;
    }

    /**
     * @param array<int, string> $legacyClaims pushes recorded by an earlier plugin version
     */
    private function order(array $legacyClaims = []): OrderEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setCustomFields([PushController::PROCESSED_PUSHES_FIELD => $legacyClaims]);

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
