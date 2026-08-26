<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\ContextStub;

class CaptureServiceTest extends TestCase
{
    private CaptureService $captureService;

    /** @var TransactionService&MockObject */
    private TransactionService $transactionService;

    /** @var UrlService&MockObject */
    private UrlService $urlService;

    /** @var InvoiceService&MockObject */
    private InvoiceService $invoiceService;

    /** @var FormatRequestParamService&MockObject */
    private FormatRequestParamService $formatRequestParamService;

    /** @var TranslatorInterface&MockObject */
    private TranslatorInterface $translator;

    /** @var ClientService&MockObject */
    private ClientService $clientService;

    /** @var object Context mock */  
    private $context;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->urlService = $this->createMock(UrlService::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->formatRequestParamService = $this->createMock(FormatRequestParamService::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->clientService = $this->createMock(ClientService::class);
        
        // Mock Context - will work in full Shopware environment
        // In CI without Shopware, we check and skip tests
        $this->context = $this->createMock(\Shopware\Core\Framework\Context::class);

        $this->captureService = new CaptureService(
            $this->transactionService,
            $this->urlService,
            $this->invoiceService,
            $this->formatRequestParamService,
            $this->translator,
            $this->clientService
        );
    }

    /**
     * Test: it returns null when order is not a Buckaroo payment
     */
    public function testCaptureReturnsNullWhenNotBuckarooPayment(): void
    {
        // Arrange
        $request = new Request();
        $order = new OrderEntity();
        
        $this->transactionService
            ->expects($this->once())
            ->method('isBuckarooPaymentMethod')
            ->with($order)
            ->willReturn(false);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test: it returns error when order amount is zero
     */
    public function testCaptureReturnsErrorWhenAmountIsZero(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(0.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canCapture' => 1,
                'captured' => 0,
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('buckaroo.capture.invalid_amount')
            ->willReturn('Invalid amount');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Invalid amount', $result['message']);
    }

    /**
     * Test: it returns error when order amount is negative
     */
    public function testCaptureReturnsErrorWhenAmountIsNegative(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(-10.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canCapture' => 1,
                'captured' => 0,
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->method('trans')
            ->with('buckaroo.capture.invalid_amount')
            ->willReturn('Invalid amount');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
    }

    /**
     * Test: it returns error when capture is not supported (canCapture = 0)
     */
    public function testCaptureReturnsErrorWhenCaptureNotSupported(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canCapture' => 0,
                'captured' => 0,
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('buckaroo.capture.capture_not_supported')
            ->willReturn('Capture not supported');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Capture not supported', $result['message']);
    }

    /**
     * Test: it returns error when order already captured
     */
    public function testCaptureReturnsErrorWhenAlreadyCaptured(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canCapture' => 1,
                'captured' => 1, // Already captured
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('buckaroo.capture.already_captured')
            ->willReturn('Already captured');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Already captured', $result['message']);
    }

    /**
     * Test: it allows capture when order has authorized flag
     */
    public function testCaptureAllowsCaptureWhenOrderIsAuthorized(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canCapture' => 0, // Normally would reject
            'captured' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $client = $this->createMock(Client::class);
        $this->setupSuccessfulCapture($client, $order);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: it uses pay action for klarnakp payment method
     */
    public function testCaptureUsesPayActionForKlarnakp(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'klarnakp',
            'canCapture' => 1,
            'captured' => 0,
            'reservationNumber' => 'RES12345'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        // Mock formatRequestParamService to return empty articles
        $this->formatRequestParamService
            ->method('getOrderLinesArray')
            ->willReturn([]);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('setAction')
            ->with('pay') // Should use 'pay' for klarnakp
            ->willReturnSelf();

        $client->expects($this->once())
            ->method('setPayload')
            ->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(true);
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        $this->invoiceService
            ->method('isInvoiced')
            ->willReturn(true);

        $this->translator
            ->method('trans')
            ->willReturn('Captured successfully');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: it uses capture action for non-klarnakp payment methods
     */
    public function testCaptureUsesCaptureActionForNonKlarnakp(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'afterpay',
            'canCapture' => 1,
            'captured' => 0,
            'originalTransactionKey' => 'TRX12345'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('setAction')
            ->with('capture') // Should use 'capture' for afterpay
            ->willReturnSelf();

        $client->expects($this->once())
            ->method('setPayload')
            ->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(true);
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        $this->invoiceService
            ->method('isInvoiced')
            ->willReturn(true);

        $this->translator
            ->method('trans')
            ->willReturn('Captured successfully');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: it generates invoice when not invoiced and not set to invoice after shipment
     */
    public function testCaptureGeneratesInvoiceWhenNotInvoiced(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canCapture' => 1,
            'captured' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $this->invoiceService
            ->expects($this->once())
            ->method('isInvoiced')
            ->with($order->getId(), $this->context)
            ->willReturn(false);

        $this->invoiceService
            ->expects($this->once())
            ->method('isCreateInvoiceAfterShipment')
            ->with(false, 'ideal', $order->getSalesChannelId())
            ->willReturn(false);

        $this->invoiceService
            ->expects($this->once())
            ->method('generateInvoice')
            ->with($order, $this->context);

        $client = $this->createMock(Client::class);
        $this->setupSuccessfulCapture($client, $order);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: it does not generate invoice when already invoiced
     */
    public function testCaptureDoesNotGenerateInvoiceWhenAlreadyInvoiced(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canCapture' => 1,
            'captured' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $this->invoiceService
            ->expects($this->once())
            ->method('isInvoiced')
            ->willReturn(true);

        $this->invoiceService
            ->expects($this->never())
            ->method('generateInvoice');

        $client = $this->createMock(Client::class);
        $this->setupSuccessfulCapture($client, $order);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: it persists the in-flight marker before the request and the captured
     * flag (releasing the marker) after a synchronous success
     */
    public function testCaptureSavesTransactionDataOnSuccess(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $transactionId = 'transaction-id-123';
        $transaction = new OrderTransactionEntity();
        $transaction->setId($transactionId);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canCapture' => 1,
            'captured' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        // Exactly ONE write, and only AFTER the response: a write before the
        // Buckaroo call would hold a row lock on order_transaction that deadlocks
        // the ship action against its own result push.
        $this->transactionService
            ->expects($this->once())
            ->method('saveTransactionData')
            ->with(
                $transactionId,
                $this->context,
                ['captured' => 1, CaptureService::CAPTURE_INITIATED => 0]
            );

        $client = $this->createMock(Client::class);
        $this->setupSuccessfulCapture($client, $order);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: the second capture trigger within the same request (the ship action
     * fires both order_delivery.written and state_enter.order_delivery.state.shipped)
     * is rejected in memory - only ONE request reaches the Buckaroo client
     */
    public function testSecondCaptureInSameRequestIsRejected(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
            ]);

        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(false);
        $response->method('isPendingProcessing')->willReturn(true);
        $client
            ->expects($this->once())
            ->method('execute')
            ->willReturn($response);

        // The Buckaroo client may be built only once.
        $this->clientService
            ->expects($this->once())
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        $this->translator
            ->method('trans')
            ->willReturnArgument(0);

        // Act
        $first = $this->captureService->capture($request, $order, $this->context);
        $second = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertTrue($first['status']);
        $this->assertFalse($second['status']);
        $this->assertSame('buckaroo.capture.capture_in_progress', $second['message']);
        // Expected dedup outcome: the capture triggers must not notify about it.
        $this->assertTrue($second['silent']);
    }

    /**
     * Test: when the connection fails mid-request the engine may still have
     * processed the capture - the in-flight marker is persisted (post-call) before
     * the exception bubbles up, so no immediate retry can send a duplicate Pay
     */
    public function testCapturePersistsInFlightMarkerOnConnectionFailure(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
            ]);

        $savedData = [];
        $this->transactionService
            ->expects($this->once())
            ->method('saveTransactionData')
            ->willReturnCallback(
                function (string $txId, $context, array $data) use (&$savedData): void {
                    $savedData[] = $data;
                }
            );

        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();
        $client->method('execute')->willThrowException(new \RuntimeException('cURL error 28'));

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        // Act + Assert
        try {
            $this->captureService->capture($request, $order, $this->context);
            $this->fail('Expected the connection failure to bubble up');
        } catch (\RuntimeException $e) {
            $this->assertSame('cURL error 28', $e->getMessage());
        }

        $this->assertCount(1, $savedData);
        $this->assertArrayHasKey(CaptureService::CAPTURE_INITIATED, $savedData[0]);
        $this->assertGreaterThan(0, $savedData[0][CaptureService::CAPTURE_INITIATED]);
    }

    /**
     * Test: a second capture attempt is rejected while a previous capture request
     * is still in flight (marker present, no result yet) - this is the Klarna MoR
     * duplicate "Pay on reservation" scenario where the ship action fires both the
     * order_delivery.written and the state_enter.order_delivery.state.shipped paths
     */
    public function testCaptureIsRejectedWhenCaptureIsInFlight(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
                CaptureService::CAPTURE_INITIATED => time() - 5,
            ]);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('buckaroo.capture.capture_in_progress')
            ->willReturn('Capture already in progress');

        // No request may reach the Buckaroo client.
        $this->clientService
            ->expects($this->never())
            ->method('get');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Capture already in progress', $result['message']);
        // Expected dedup outcome: the capture triggers must not notify about it.
        $this->assertTrue($result['silent']);
    }

    /**
     * Test: an expired in-flight marker no longer blocks a capture, so the
     * mandatory Klarna MoR capture-on-shipment flow cannot be blocked permanently
     */
    public function testCaptureIsAllowedWhenInFlightMarkerExpired(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
                CaptureService::CAPTURE_INITIATED =>
                    time() - CaptureService::CAPTURE_IN_FLIGHT_SECONDS - 1,
            ]);

        $client = $this->createMock(Client::class);
        $this->setupSuccessfulCapture($client, $order);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
    }

    /**
     * Test: a 791 pending-processing response (Klarna MoR Pay is processed
     * asynchronously by the engine) is reported as initiated - not as a failure -
     * and the in-flight marker is persisted (post-call, never before the request)
     * so no second Pay can be sent before the push arrives
     */
    public function testCaptureKeepsInFlightMarkerOnPendingProcessing(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
            ]);

        // Exactly one write: the post-call in-flight marker; nothing may clear it.
        $savedData = [];
        $this->transactionService
            ->expects($this->once())
            ->method('saveTransactionData')
            ->willReturnCallback(
                function (string $txId, $context, array $data) use (&$savedData): void {
                    $savedData[] = $data;
                }
            );

        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(false);
        $response->method('isPendingProcessing')->willReturn(true);
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->translator
            ->method('trans')
            ->with('buckaroo.capture.capture_pending')
            ->willReturn('Capture pending');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertTrue($result['status']);
        $this->assertSame('Capture pending', $result['message']);
        $this->assertCount(1, $savedData);
        $this->assertArrayHasKey(CaptureService::CAPTURE_INITIATED, $savedData[0]);
        $this->assertGreaterThan(0, $savedData[0][CaptureService::CAPTURE_INITIATED]);
    }

    /**
     * Test: a definitive failure persists nothing, so the capture can simply be
     * retried by a later shipment event or manually
     */
    public function testCaptureWritesNothingOnDefinitiveFailure(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);

        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'klarna',
                'canCapture' => 1,
                'dataRequestKey' => 'DRK-123',
            ]);

        $this->transactionService
            ->expects($this->never())
            ->method('saveTransactionData');

        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(false);
        $response->method('isPendingProcessing')->willReturn(false);
        $response->method('getSomeError')->willReturn('Engine error');
        $response->method('getStatusCode')->willReturn(490);
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Engine error', $result['message']);
    }

    /**
     * Test: isCaptureInFlight boundary behaviour
     */
    public function testIsCaptureInFlight(): void
    {
        $this->assertFalse(CaptureService::isCaptureInFlight([]));
        $this->assertFalse(CaptureService::isCaptureInFlight([CaptureService::CAPTURE_INITIATED => 0]));
        $this->assertFalse(CaptureService::isCaptureInFlight([CaptureService::CAPTURE_INITIATED => 'abc']));
        $this->assertTrue(CaptureService::isCaptureInFlight([CaptureService::CAPTURE_INITIATED => time()]));
        $this->assertFalse(CaptureService::isCaptureInFlight([
            CaptureService::CAPTURE_INITIATED => time() - CaptureService::CAPTURE_IN_FLIGHT_SECONDS - 1,
        ]));
    }

    /**
     * Test: it returns error response when API call fails
     */
    public function testCaptureReturnsErrorWhenApiCallFails(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        $order->setCustomFields([CaptureService::ORDER_IS_AUTHORIZED => true]);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canCapture' => 1,
            'captured' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(false);
        $response->method('getSomeError')->willReturn('Payment gateway error');
        $response->method('getStatusCode')->willReturn(400);
        
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        // Act
        $result = $this->captureService->capture($request, $order, $this->context);

        // Assert
        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertSame('Payment gateway error', $result['message']);
        $this->assertSame(400, $result['code']);
    }

    /**
     * Test: it throws exception when serviceName is missing
     */
    public function testCaptureThrowsExceptionWhenServiceNameMissing(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([]); // Missing serviceName

        // Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Cannot find field `serviceName` on order');

        // Act
        $this->captureService->capture($request, $order, $this->context);
    }

    /**
     * Test: it throws exception when serviceName is not a string
     */
    public function testCaptureThrowsExceptionWhenServiceNameNotString(): void
    {
        // Arrange
        $request = new Request();
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn(['serviceName' => 123]); // Not a string

        // Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Cannot find field `serviceName` on order');

        // Act
        $this->captureService->capture($request, $order, $this->context);
    }

    // Helper methods

    private function createOrder(float $amount): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id-123');
        $order->setOrderNumber('ORDER-001');
        $order->setAmountTotal($amount);
        $order->setSalesChannelId('sales-channel-123');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }

    private function setupSuccessfulCapture(MockObject $client, OrderEntity $order): void
    {
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(true);
        
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->with($this->anything(), $order->getSalesChannelId())
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        $this->invoiceService
            ->method('isInvoiced')
            ->willReturn(true);

        $this->translator
            ->method('trans')
            ->willReturn('Captured successfully');
    }
}
