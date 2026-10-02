<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;
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
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A correctly signed push of a Buckaroo test transaction must not move an order whose
 * payment method is configured live, and a push must name the website key of the order's
 * sales channel. Such a push is rejected before anything acts on it.
 */
class PushControllerEnvironmentTest extends TestCase
{
    private const TRANSACTION_ID = 'order-transaction-id';

    private const SALES_CHANNEL_ID = 'sales-channel-id';

    private const WEBSITE_KEY = 'WebsiteKey123';

    public function testSignedTestPushForALiveMethodDoesNotTransitionTheOrder(): void
    {
        $response = $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $this->checkoutHelper(testEnvironment: false),
            $this->untouchable(TransactionService::class)
        );

        $this->assertRejected($response);
    }

    public function testSignedTestPushForAMethodThatIsNotBuckarooIsRejected(): void
    {
        $response = $this->handlePush(
            $this->pushData(['brq_test' => 'true']),
            $this->checkoutHelper(testEnvironment: true, handlerIdentifier: 'Some\\Other\\Handler'),
            $this->untouchable(TransactionService::class)
        );

        $this->assertRejected($response);
    }

    public function testTestPushForATestMethodIsProcessed(): void
    {
        $postData = $this->pushData(['brq_test' => 'true']);

        $response = $this->handlePush(
            $postData,
            $this->checkoutHelper(testEnvironment: true),
            $this->transactionServiceWithClaimOf($postData)
        );

        $this->assertPassedTheBinding($response);
    }

    public function testLivePushForALiveMethodIsProcessed(): void
    {
        $postData = $this->pushData(['brq_test' => 'false']);

        $checkoutHelper = $this->checkoutHelper(testEnvironment: false);
        $checkoutHelper->expects($this->never())->method('isTestEnvironment');

        $response = $this->handlePush($postData, $checkoutHelper, $this->transactionServiceWithClaimOf($postData));

        $this->assertPassedTheBinding($response);
    }

    public function testPushForAnotherWebsiteKeyIsRejected(): void
    {
        $response = $this->handlePush(
            $this->pushData(['brq_websitekey' => 'OtherWebsiteKey']),
            $this->checkoutHelper(testEnvironment: false),
            $this->untouchable(TransactionService::class)
        );

        $this->assertRejected($response);
    }

    public function testPushForTheWebsiteKeyOfTheSalesChannelIsProcessed(): void
    {
        $postData = $this->pushData(['brq_websitekey' => self::WEBSITE_KEY]);

        $response = $this->handlePush(
            $postData,
            $this->checkoutHelper(testEnvironment: false),
            $this->transactionServiceWithClaimOf($postData)
        );

        $this->assertPassedTheBinding($response);
    }

    private function assertRejected(JsonResponse $response): void
    {
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * The push got past the order binding: it reached the replay check, which stops it
     * because the test records it as already processed.
     */
    private function assertPassedTheBinding(JsonResponse $response): void
    {
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.pushAlreadySend'],
            $this->decode($response)
        );
    }

    /**
     * @return CheckoutHelper&\PHPUnit\Framework\MockObject\MockObject
     */
    private function checkoutHelper(bool $testEnvironment, string $handlerIdentifier = IdealQrPaymentHandler::class)
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('payment-method-id');
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setPaymentMethod($paymentMethod);

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturn($order);
        $checkoutHelper->method('getSettingsValue')
            ->with('websiteKey', self::SALES_CHANNEL_ID)
            ->willReturn(self::WEBSITE_KEY);
        $checkoutHelper->method('isTestEnvironment')
            ->with('idealqr', self::SALES_CHANNEL_ID)
            ->willReturn($testEnvironment);

        return $checkoutHelper;
    }

    /**
     * @param array<string, string> $postData
     *
     * @return TransactionService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function transactionServiceWithClaimOf(array $postData)
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TRANSACTION_ID);
        $orderTransaction->setCustomFields([PushController::PROCESSED_PUSHES_FIELD => [strtoupper(implode('|', [
            $postData['brq_transactions'],
            $postData['brq_statuscode'],
            $postData['brq_transaction_type'],
            $postData['brq_mutationtype'],
        ]))]]);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('getOrderTransactionById')->willReturn($orderTransaction);

        return $transactionService;
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
            'brq_transaction_method' => 'idealqr',
            'brq_transaction_type' => 'C021',
            'brq_mutationtype' => 'Processing',
            'brq_transactions' => 'key-123',
            'ADD_orderId' => 'order-id',
            'ADD_orderTransactionId' => self::TRANSACTION_ID,
        ];
    }

    /**
     * Run the push action with a valid signature. State transitions must never happen.
     *
     * @param array<string, string> $postData
     */
    private function handlePush(
        array $postData,
        CheckoutHelper $checkoutHelper,
        TransactionService $transactionService
    ): JsonResponse {
        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateSignature')->willReturn(true);

        /** @var StateTransitionService $stateTransitionService */
        $stateTransitionService = $this->untouchable(StateTransitionService::class);
        /** @var InvoiceService $invoiceService */
        $invoiceService = $this->untouchable(InvoiceService::class);
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->untouchable(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class);
        /** @var IdealQrOrderRepository $idealQrRepository */
        $idealQrRepository = $this->untouchable(IdealQrOrderRepository::class);
        /** @var OrderService $orderService */
        $orderService = $this->untouchable(OrderService::class);
        /** @var CustomerService $customerService */
        $customerService = $this->untouchable(CustomerService::class);

        $controller = new PushController(
            $signatureValidationService,
            $transactionService,
            $stateTransitionService,
            $invoiceService,
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $eventDispatcher,
            $idealQrRepository,
            $orderService,
            $customerService
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
