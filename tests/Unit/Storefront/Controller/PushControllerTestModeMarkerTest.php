<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
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
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A successful push records on the order whether its payment was made on the Buckaroo test
 * environment, so the administration can show it. Only the current payment of the order
 * decides, and only a processed successful push writes it.
 */
class PushControllerTestModeMarkerTest extends TestCase
{
    private const TRANSACTION_ID = 'order-transaction-id';

    private const EARLIER_TRANSACTION_ID = 'earlier-order-transaction-id';

    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testSuccessfulTestPushMarksTheOrder(): void
    {
        $order = $this->order();
        $checkoutHelper = $this->checkoutHelper($order, testEnvironment: true);
        $checkoutHelper->expects($this->once())
            ->method('setPaymentInTestMode')
            ->with($this->identicalTo($order), true, $this->isInstanceOf(Context::class));

        $stateTransitionService = $this->stateTransitionService();
        $stateTransitionService->expects($this->once())
            ->method('transitionPaymentState')
            ->with('completed', self::TRANSACTION_ID, $this->isInstanceOf(Context::class));

        $response = $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $checkoutHelper,
            stateTransitionService: $stateTransitionService
        );

        $this->assertSame(
            ['status' => true, 'message' => 'buckaroo.messages.paymentUpdated'],
            $this->decode($response)
        );
    }

    /**
     * @dataProvider livePushFlags
     */
    public function testSuccessfulLivePushDoesNotMarkTheOrderAsTest(?string $testFlag): void
    {
        $order = $this->order();
        $checkoutHelper = $this->checkoutHelper($order, testEnvironment: false);
        $checkoutHelper->expects($this->once())
            ->method('setPaymentInTestMode')
            ->with($this->identicalTo($order), false, $this->isInstanceOf(Context::class));

        $overrides = $testFlag === null ? [] : ['brq_test' => $testFlag];
        $response = $this->handlePush($this->pushData($overrides), $checkoutHelper);

        $this->assertSame(
            ['status' => true, 'message' => 'buckaroo.messages.paymentUpdated'],
            $this->decode($response)
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function livePushFlags(): array
    {
        return [
            'false' => ['false'],
            'zero' => ['0'],
            'empty' => [''],
            'missing' => [null],
        ];
    }

    public function testSuccessfulAuthorizePushMarksTheOrder(): void
    {
        $order = $this->order();
        $checkoutHelper = $this->checkoutHelper($order, testEnvironment: true);
        $checkoutHelper->expects($this->once())
            ->method('setPaymentInTestMode')
            ->with($this->identicalTo($order), true, $this->isInstanceOf(Context::class));

        $stateTransitionService = $this->stateTransitionService();
        $stateTransitionService->expects($this->once())
            ->method('transitionPaymentState')
            ->with('authorize', self::TRANSACTION_ID, $this->isInstanceOf(Context::class));

        $this->handlePush(
            $this->pushData(['brq_test' => 'true', 'brq_primary_service' => 'klarnakp']),
            $checkoutHelper,
            stateTransitionService: $stateTransitionService
        );
    }

    /**
     * A late push of an earlier payment attempt must not change what the order shows for
     * the payment that replaced it.
     */
    public function testSuccessfulPushOfAnEarlierAttemptLeavesTheMarkerAlone(): void
    {
        $order = $this->order(withLaterTransaction: true);
        $checkoutHelper = $this->checkoutHelper($order, testEnvironment: true);
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $this->handlePush(
            $this->pushData(['brq_test' => 'true', 'ADD_orderTransactionId' => self::EARLIER_TRANSACTION_ID]),
            $checkoutHelper,
            lastTransactionId: self::TRANSACTION_ID,
            claimedTransactionId: self::EARLIER_TRANSACTION_ID
        );
    }

    /**
     * @dataProvider unsuccessfulStatuses
     */
    public function testUnsuccessfulTestPushDoesNotMarkTheOrder(string $statusCode): void
    {
        $checkoutHelper = $this->checkoutHelper($this->order(), testEnvironment: true);
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $this->handlePush($this->pushData(['brq_test' => 'true', 'brq_statuscode' => $statusCode]), $checkoutHelper);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsuccessfulStatuses(): array
    {
        return [
            'failed' => ['490'],
            'rejected' => ['690'],
            'cancelled by user' => ['890'],
            'pending' => ['791'],
        ];
    }

    public function testPushWithoutValidSignatureDoesNotMarkTheOrder(): void
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $checkoutHelper,
            validSignature: false,
            processedPushService: $this->untouchable(ProcessedPushService::class)
        );
    }

    public function testTestPushForALiveMethodDoesNotMarkTheOrder(): void
    {
        $checkoutHelper = $this->checkoutHelper($this->order(), testEnvironment: false);
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $response = $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $checkoutHelper,
            processedPushService: $this->untouchable(ProcessedPushService::class)
        );

        $this->assertSame(['status' => false, 'message' => 'buckaroo.messages.paymentError'], $this->decode($response));
    }

    public function testAlreadyProcessedPushDoesNotMarkTheOrderAgain(): void
    {
        $checkoutHelper = $this->checkoutHelper($this->order(), testEnvironment: true);
        $checkoutHelper->expects($this->never())->method('setPaymentInTestMode');

        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->method('claim')->willReturn(false);

        $response = $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $checkoutHelper,
            processedPushService: $processedPushService
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.pushAlreadySend'],
            $this->decode($response)
        );
    }

    /**
     * @return CheckoutHelper&MockObject
     */
    private function checkoutHelper(OrderEntity $order, bool $testEnvironment)
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturn($order);
        $checkoutHelper->method('isTestEnvironment')
            ->with('ideal', self::SALES_CHANNEL_ID)
            ->willReturn($testEnvironment);

        return $checkoutHelper;
    }

    /**
     * @return StateTransitionService&MockObject
     */
    private function stateTransitionService()
    {
        $stateTransitionService = $this->createMock(StateTransitionService::class);
        $stateTransitionService->method('isOrderState')->willReturn(false);
        $stateTransitionService->method('isTransitionPaymentState')->willReturn(false);
        $stateTransitionService->method('canTransitionStatus')->willReturn(true);

        return $stateTransitionService;
    }

    private function order(bool $withLaterTransaction = false): OrderEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-id');
        $paymentMethod->setHandlerIdentifier(IdealPaymentHandler::class);

        $transactions = [];
        if ($withLaterTransaction) {
            $earlier = new OrderTransactionEntity();
            $earlier->setId(self::EARLIER_TRANSACTION_ID);
            $earlier->setPaymentMethod($paymentMethod);
            $transactions[] = $earlier;
        }

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setPaymentMethod($paymentMethod);
        $transactions[] = $orderTransaction;

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setTransactions(new OrderTransactionCollection($transactions));
        $order->setPrice(new CartPrice(
            10.0,
            10.0,
            10.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        ));

        return $order;
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
     * Run the push action. The signature service validates and returns the posted values.
     *
     * @param array<string, string> $postData
     */
    private function handlePush(
        array $postData,
        CheckoutHelper $checkoutHelper,
        bool $validSignature = true,
        ?ProcessedPushService $processedPushService = null,
        ?StateTransitionService $stateTransitionService = null,
        string $lastTransactionId = self::TRANSACTION_ID,
        string $claimedTransactionId = self::TRANSACTION_ID
    ): JsonResponse {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateSignature')->willReturn($validSignature);
        $signatureValidationService->method('getSignedPushData')->willReturn($postData);

        if ($processedPushService === null) {
            $processedPushService = $this->createMock(ProcessedPushService::class);
            $processedPushService->method('claim')
                ->with($this->anything(), $claimedTransactionId)
                ->willReturn(true);
        }

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('getLastTransactionId')->willReturn($lastTransactionId);
        $transactionService->method('getCustomFields')->willReturn([]);

        $controller = new PushController(
            $signatureValidationService,
            $transactionService,
            $stateTransitionService ?? $this->stateTransitionService(),
            $this->createMock(InvoiceService::class),
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
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
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $controller->pushBuckaroo(new Request([], $postData), $salesChannelContext);
    }

    /**
     * @param class-string $className
     *
     * @return MockObject
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
