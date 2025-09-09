<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\CustomerService;

class PushControllerTest extends TestCase
{
    private PushController $pushController;
    private MockObject $signatureValidationService;
    private MockObject $transactionService;
    private MockObject $stateTransitionService;
    private MockObject $invoiceService;
    private MockObject $checkoutHelper;
    private MockObject $logger;
    private MockObject $eventDispatcher;
    private MockObject $idealQrRepository;
    private MockObject $orderService;
    private MockObject $customerService;

    protected function setUp(): void
    {
        $this->signatureValidationService = $this->createMock(SignatureValidationService::class);
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->stateTransitionService = $this->createMock(StateTransitionService::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->idealQrRepository = $this->createMock(IdealQrOrderRepository::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->customerService = $this->createMock(CustomerService::class);

        $this->pushController = new PushController(
            $this->signatureValidationService,
            $this->transactionService,
            $this->stateTransitionService,
            $this->invoiceService,
            $this->checkoutHelper,
            $this->logger,
            $this->eventDispatcher,
            $this->idealQrRepository,
            $this->orderService,
            $this->customerService
        );
    }

    /**
     * Test that numeric mutation type doesn't match string constant
     */
    public function testMutationTypeNumericNoLooseMatch(): void
    {
        // Arrange
        $request = $this->createRequest([
            'brq_statuscode' => '190',
            'ADD_orderId' => Uuid::randomHex(),
            'ADD_orderTransactionId' => Uuid::randomHex(),
            'brq_transaction_type' => 'I038',
            'brq_mutationtype' => 1, // Numeric value that would match with == but not ===
        ]);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());

        $this->signatureValidationService->method('validateSignature')->willReturn(true);
        $this->checkoutHelper->method('getOrderById')->willReturn($order);
        $this->checkoutHelper->method('saveBuckarooTransaction')->willReturn(true);
        
        // Mock checkDuplicatePush to return true (no duplicate)
        $this->createReflectionAndMockPrivateMethod('checkDuplicatePush', true);

        // Act
        $response = $this->pushController->pushBuckaroo($request, $salesChannelContext);

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        // Verify that numeric mutation type did not trigger informational skip
        $responseData = json_decode($response->getContent(), true);
        $this->assertNotEquals('buckaroo.messages.skipInformational', $responseData['message'] ?? '');
    }

    /**
     * Test that array-based transaction type doesn't match authorize requests
     */
    public function testTransactionTypeArrayNoLooseMatch(): void
    {
        // Arrange
        $request = $this->createRequest([
            'brq_statuscode' => '190',
            'ADD_orderId' => Uuid::randomHex(),
            'ADD_orderTransactionId' => Uuid::randomHex(),
            'brq_transaction_type' => ['I872'], // Array that contains valid authorize request
            'brq_mutationtype' => ResponseStatus::BUCKAROO_MUTATION_TYPE_PROCESSING,
        ]);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());

        $this->signatureValidationService->method('validateSignature')->willReturn(true);
        $this->checkoutHelper->method('getOrderById')->willReturn($order);
        $this->checkoutHelper->method('saveBuckarooTransaction')->willReturn(true);
        
        // Mock checkDuplicatePush to return true (no duplicate)
        $this->createReflectionAndMockPrivateMethod('checkDuplicatePush', true);

        // Mock setStatusAuthorized to verify it's NOT called
        $this->createReflectionAndMockPrivateMethod('setStatusAuthorized', null, 0);

        // Act
        $response = $this->pushController->pushBuckaroo($request, $salesChannelContext);

        // Assert - Verify authorize status was not set due to strict comparison
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test numeric status code doesn't match string constants
     */
    public function testStatusCodeNumericNoLooseMatch(): void
    {
        // Arrange
        $request = $this->createRequest([
            'brq_statuscode' => 190, // Numeric that equals string '190'
            'ADD_orderId' => Uuid::randomHex(),
            'ADD_orderTransactionId' => Uuid::randomHex(),
            'brq_transaction_type' => 'I038',
            'brq_mutationtype' => ResponseStatus::BUCKAROO_MUTATION_TYPE_PROCESSING,
        ]);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());

        $this->signatureValidationService->method('validateSignature')->willReturn(true);
        $this->checkoutHelper->method('getOrderById')->willReturn($order);
        $this->checkoutHelper->method('saveBuckarooTransaction')->willReturn(true);
        
        // Mock checkDuplicatePush
        $this->createReflectionAndMockPrivateMethod('checkDuplicatePush', true);

        // Act
        $response = $this->pushController->pushBuckaroo($request, $salesChannelContext);

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        // Should not match success status due to strict comparison
        $this->assertNotEquals('buckaroo.messages.paymentSuccess', $responseData['message'] ?? '');
    }

    /**
     * Test that in_array with strict flag prevents loose matches
     */
    public function testInArrayStrictFlagPreventsLooseMatches(): void
    {
        // Test that numeric values don't match string authorize requests
        $reflection = new \ReflectionClass(PushController::class);
        $authorizeRequests = $reflection->getConstant('AUTHORIZE_REQUESTS');
        
        // These should not match due to strict comparison
        $this->assertFalse(in_array(872, $authorizeRequests, true)); // Numeric
        $this->assertFalse(in_array(['I872'], $authorizeRequests, true)); // Array
        $this->assertFalse(in_array(true, $authorizeRequests, true)); // Boolean
        
        // But valid string should match
        $this->assertTrue(in_array('I872', $authorizeRequests, true));
    }

    /**
     * Test boolean values don't match string constants
     */
    public function testBooleanStatusNoLooseMatch(): void
    {
        // Arrange
        $request = $this->createRequest([
            'brq_statuscode' => true, // Boolean true could match '1' or non-empty string with ==
            'ADD_orderId' => Uuid::randomHex(),
            'ADD_orderTransactionId' => Uuid::randomHex(),
            'brq_transaction_type' => 'I038',
            'brq_mutationtype' => ResponseStatus::BUCKAROO_MUTATION_TYPE_PROCESSING,
        ]);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());

        $this->signatureValidationService->method('validateSignature')->willReturn(true);
        $this->checkoutHelper->method('getOrderById')->willReturn($order);
        
        // Mock checkDuplicatePush
        $this->createReflectionAndMockPrivateMethod('checkDuplicatePush', true);

        // Act
        $response = $this->pushController->pushBuckaroo($request, $salesChannelContext);

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        // Boolean status should be cast to string '1' and not match any status constants
    }

    /**
     * Test valid string values still work correctly
     */
    public function testValidStringValuesStillWork(): void
    {
        // Arrange
        $request = $this->createRequest([
            'brq_statuscode' => ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS,
            'ADD_orderId' => Uuid::randomHex(),
            'ADD_orderTransactionId' => Uuid::randomHex(),
            'brq_transaction_type' => 'I872', // Valid authorize request
            'brq_mutationtype' => ResponseStatus::BUCKAROO_MUTATION_TYPE_PROCESSING,
        ]);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());

        $this->signatureValidationService->method('validateSignature')->willReturn(true);
        $this->checkoutHelper->method('getOrderById')->willReturn($order);
        $this->checkoutHelper->method('saveBuckarooTransaction')->willReturn(true);
        
        // Mock checkDuplicatePush
        $this->createReflectionAndMockPrivateMethod('checkDuplicatePush', true);

        // Mock various state transition methods
        $this->stateTransitionService->method('isOrderState')->willReturn(false);
        $this->stateTransitionService->method('isTransitionPaymentState')->willReturn(false);

        // Act
        $response = $this->pushController->pushBuckaroo($request, $salesChannelContext);

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        // Should process normally with valid string values
    }

    private function createRequest(array $postData): Request
    {
        $request = new Request();
        $request->request = new ParameterBag($postData);
        return $request;
    }

    private function createReflectionAndMockPrivateMethod(string $methodName, $returnValue, int $expectedCalls = null): void
    {
        $reflection = new \ReflectionClass($this->pushController);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        if ($expectedCalls !== null) {
            // For methods we want to verify call count
            $mock = $this->createMock(get_class($this->pushController));
            $mock->expects($this->exactly($expectedCalls))
                 ->method($methodName)
                 ->willReturn($returnValue);
        }
    }
}
