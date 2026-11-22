<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\RefundService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\ContextStub;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\PhpNoticeGuard;

class RefundServiceTest extends TestCase
{
    use PhpNoticeGuard;

    private RefundService $refundService;

    /** @var BuckarooTransactionEntityRepository&MockObject */
    private BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var TransactionService&MockObject */
    private TransactionService $transactionService;

    /** @var UrlService&MockObject */
    private UrlService $urlService;

    /** @var StateTransitionService&MockObject */
    private StateTransitionService $stateTransitionService;

    /** @var TranslatorInterface&MockObject */
    private TranslatorInterface $translator;

    /** @var ClientService&MockObject */
    private ClientService $clientService;

    /** @var object Context mock */
    private $context;

    protected function setUp(): void
    {
        $this->buckarooTransactionEntityRepository = $this->createMock(BuckarooTransactionEntityRepository::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->urlService = $this->createMock(UrlService::class);
        $this->stateTransitionService = $this->createMock(StateTransitionService::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->clientService = $this->createMock(ClientService::class);
        // Mock Context - bootstrap handles PHP 8.2 compatibility
        $this->context = $this->createMock(\Shopware\Core\Framework\Context::class);

        $this->refundService = new RefundService(
            $this->buckarooTransactionEntityRepository,
            $this->settingsService,
            $this->transactionService,
            $this->urlService,
            $this->stateTransitionService,
            $this->translator,
            $this->clientService
        );
    }

    /**
     * Test: it returns empty array when order is not a Buckaroo payment
     */
    public function testRefundAllReturnsEmptyArrayWhenNotBuckarooPayment(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => []]);
        $order = new OrderEntity();
        
        $this->transactionService
            ->expects($this->once())
            ->method('isBuckarooPaymentMethod')
            ->with($order)
            ->willReturn(false);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, []);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test: it throws exception when orderItems is not an array
     */
    public function testRefundAllThrowsExceptionWhenOrderItemsNotArray(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => 'invalid']);
        $order = new OrderEntity();
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OrderItems must be an array');

        // Act
        $this->refundService->refundAll($request, $order, $this->context, []);
    }

    /**
     * Test: it returns validation error when amount is zero
     */
    public function testRefundAllReturnsErrorWhenAmountIsZero(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => []]);
        $order = $this->createOrder(0.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canRefund' => 1,
                'refunded' => 0,
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->method('trans')
            ->with('buckaroo-payment.capture.invalid_amount')
            ->willReturn('Invalid amount');

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, []);

        // Assert
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['status']);
        $this->assertSame('Invalid amount', $result[0]['message']);
    }

    /**
     * Test: it returns validation error when refund not supported
     */
    public function testRefundAllReturnsErrorWhenRefundNotSupported(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => []]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canRefund' => 0, // Refund not supported
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->method('trans')
            ->with('buckaroo-payment.refund.not_supported')
            ->willReturn('Refund not supported');

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, []);

        // Assert
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['status']);
        $this->assertSame('Refund not supported', $result[0]['message']);
    }

    /**
     * Test: it returns validation error when already refunded
     */
    public function testRefundAllReturnsErrorWhenAlreadyRefunded(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => []]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canRefund' => 1,
                'refunded' => 1, // Already refunded
                'originalTransactionKey' => 'abc123'
            ]);

        $this->translator
            ->method('trans')
            ->with('buckaroo-payment.refund.already_refunded')
            ->willReturn('Already refunded');

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, []);

        // Assert
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['status']);
        $this->assertSame('Already refunded', $result[0]['message']);
    }

    /**
     * Test: it returns validation error when originalTransactionKey is missing
     */
    public function testRefundAllReturnsErrorWhenOriginalTransactionKeyMissing(): void
    {
        // Arrange
        $request = new Request([], ['orderItems' => []]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn([
                'serviceName' => 'ideal',
                'canRefund' => 1,
                'refunded' => 0
                // Missing originalTransactionKey
            ]);

        $this->translator
            ->method('trans')
            ->with('buckaroo-payment.general_error')
            ->willReturn('General error');

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, []);

        // Assert
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['status']);
        $this->assertSame('General error', $result[0]['message']);
    }

    /**
     * Test: it calculates max amount from custom refund amount
     */
    public function testGetMaxAmountReturnsCustomRefundAmount(): void
    {
        // Arrange
        $orderItems = [];
        $customRefundAmount = 50.00;

        // Act
        $result = $this->refundService->getMaxAmount($orderItems, $customRefundAmount);

        // Assert
        $this->assertSame(50.00, $result);
    }

    /**
     * Test: it calculates max amount from order items when no custom amount
     */
    public function testGetMaxAmountCalculatesFromOrderItems(): void
    {
        // Arrange
        $orderItems = [
            ['totalAmount' => 10.00],
            ['totalAmount' => 20.00],
            ['totalAmount' => 15.00]
        ];

        // Act
        $result = $this->refundService->getMaxAmount($orderItems, null);

        // Assert
        $this->assertSame(45.00, $result);
    }

    /**
     * Test: it returns 0 when no order items and no custom amount
     */
    public function testGetMaxAmountReturnsZeroWhenNoItemsOrAmount(): void
    {
        // Arrange
        $orderItems = [];

        // Act
        $result = $this->refundService->getMaxAmount($orderItems, null);

        // Assert
        $this->assertSame(0.0, $result);
    }

    /**
     * Test: it prefers custom refund amount over order items total
     */
    public function testGetMaxAmountPrefersCustomAmountOverItems(): void
    {
        // Arrange
        $orderItems = [
            ['totalAmount' => 100.00]
        ];
        $customRefundAmount = 50.00;

        // Act
        $result = $this->refundService->getMaxAmount($orderItems, $customRefundAmount);

        // Assert
        $this->assertSame(50.00, $result);
    }

    /**
     * Test: it returns config code from custom fields
     */
    public function testGetConfigCodeReturnsServiceName(): void
    {
        // Arrange
        $customFields = ['serviceName' => 'ideal'];

        // Act
        $result = $this->refundService->getConfigCode($customFields);

        // Assert
        $this->assertSame('ideal', $result);
    }

    /**
     * Test: it throws exception when serviceName is not a string
     */
    public function testGetConfigCodeThrowsExceptionWhenServiceNameNotString(): void
    {
        // Arrange
        $customFields = ['serviceName' => 123];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name is not a string');

        // Act
        $this->refundService->getConfigCode($customFields);
    }

    /**
     * Test: it processes partial refund correctly
     */
    public function testRefundAllProcessesPartialRefund(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 50.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 100.00,
                'transactions' => 'TRX123'
            ]
        ];

        $this->setupSuccessfulRefund($order, 50.00);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
    }

    /**
     * Test: it stops refunding when amount remaining is zero
     */
    public function testRefundAllStopsWhenAmountRemainingIsZero(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 50.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        // Two transactions but amount is only 50
        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 50.00,
                'transactions' => 'TRX123'
            ],
            [
                'id' => 'trx-2',
                'amount' => 50.00,
                'transactions' => 'TRX456'
            ]
        ];

        $this->setupSuccessfulRefund($order, 50.00);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert - Should only refund once
        $this->assertCount(1, $result);
    }

    /**
     * Test: it skips transaction when amount is zero or negative
     */
    public function testRefundAllSkipsTransactionWhenAmountIsZero(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 50.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 0.00,
                'transactions' => 'TRX123'
            ]
        ];

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert - Should be empty, no refunds processed
        $this->assertEmpty($result);
    }

    /**
     * Test: it rejects partial refund for non-fashioncheque giftcards
     */
    public function testRefundAllRejectsPartialRefundForGiftcard(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 25.00 // Less than transaction amount
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'giftcards',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 50.00,
                'transactions' => 'TRX123',
                'transaction_method' => 'vvv' // Not fashioncheque
            ]
        ];

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['status']);
        $this->assertStringContainsString('Cannot partial refund giftcard', $result[0]['message']);
    }

    /**
     * Test: it allows partial refund for fashioncheque giftcard
     */
    public function testRefundAllAllowsPartialRefundForFashioncheque(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 25.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'giftcards',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 50.00,
                'transactions' => 'TRX123',
                'transaction_method' => 'fashioncheque'
            ]
        ];

        $this->setupSuccessfulRefund($order, 25.00);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
    }


    /**
     * Test: it transitions to refunded state when full refund
     */
    public function testRefundTransitionsToRefundedStateWhenFullRefund(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 100.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 100.00,
                'transactions' => 'TRX123'
            ]
        ];

        $this->stateTransitionService
            ->expects($this->once())
            ->method('transitionPaymentState')
            ->with('refunded', $this->anything(), $this->context);

        $this->setupSuccessfulRefund($order, 100.00);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
    }

    /**
     * Test: it transitions to partial_refunded state when partial refund
     */
    public function testRefundTransitionsToPartialRefundedStateWhenPartialRefund(): void
    {
        // Arrange
        $request = new Request([], [
            'orderItems' => [],
            'customRefundAmount' => 50.00
        ]);
        $order = $this->createOrder(100.00);
        
        $this->transactionService
            ->method('isBuckarooPaymentMethod')
            ->willReturn(true);

        $customFields = [
            'serviceName' => 'ideal',
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123'
        ];

        $this->transactionService
            ->method('getCustomFields')
            ->willReturn($customFields);

        $transactionsToRefund = [
            [
                'id' => 'trx-1',
                'amount' => 100.00,
                'transactions' => 'TRX123'
            ]
        ];

        $this->stateTransitionService
            ->expects($this->once())
            ->method('transitionPaymentState')
            ->with('partial_refunded', $this->anything(), $this->context);

        $this->setupSuccessfulRefund($order, 50.00);

        // Act
        $result = $this->refundService->refundAll($request, $order, $this->context, $transactionsToRefund);

        // Assert
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
    }

    /**
     * @dataProvider invalidOrderItemProvider
     */
    public function testRefundAllRejectsOrderItemWithInvalidTotalAmount(mixed $orderItem): void
    {
        $request = new Request([], ['orderItems' => [
            ['id' => 'item-1', 'quantity' => 1, 'totalAmount' => '10.00'],
            $orderItem,
        ]]);
        $order = $this->createOrder(100.00);
        $this->mockRefundableOrder('ideal');
        $this->translator->method('trans')->willReturnArgument(0);

        $this->clientService->expects($this->never())->method('get');
        $this->stateTransitionService->expects($this->never())->method('transitionPaymentState');

        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->refundAll($request, $order, $this->context, [
                ['id' => 'trx-1', 'amount' => 100.00, 'transactions' => 'TRX123'],
            ])
        );

        $this->assertSame([[
            'status' => false,
            'message' => 'buckaroo-payment.paylink.invalid_amount',
        ]], $result);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidOrderItemProvider(): array
    {
        return [
            'non-numeric string' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => 'abc']],
            'negative number' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => -5]],
            'negative numeric string' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => '-5.00']],
            'null' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => null]],
            'array' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => ['10.00']]],
            'object' => [['id' => 'item-2', 'quantity' => 1, 'totalAmount' => new \stdClass()]],
            'missing' => [['id' => 'item-2', 'quantity' => 1]],
            'item is not an array' => ['item-2'],
        ];
    }

    /**
     * @dataProvider validTotalAmountProvider
     */
    public function testRefundAllUsesNumericOrderItemTotals(mixed $totalAmount, float $expectedAmount): void
    {
        $request = new Request([], ['orderItems' => [
            ['id' => 'item-1', 'quantity' => 1, 'totalAmount' => '10.10'],
            ['id' => 'item-2', 'quantity' => 1, 'totalAmount' => $totalAmount],
        ]]);
        $order = $this->createOrder(100.00);
        $this->mockRefundableOrder('ideal');

        $payloads = [];
        $client = $this->mockClient(true);
        $client->method('setPayload')->willReturnCallback(
            function (array $payload) use (&$payloads, $client) {
                $payloads[] = $payload;
                return $client;
            }
        );
        $this->clientService->expects($this->once())->method('get')->willReturn($client);
        $this->translator->method('trans')->willReturnArgument(0);

        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->refundAll($request, $order, $this->context, [
                ['id' => 'trx-1', 'amount' => 100.00, 'transactions' => 'TRX123'],
            ])
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
        $this->assertCount(1, $payloads);
        $this->assertEqualsWithDelta($expectedAmount, $payloads[0]['amountCredit'], 0.00001);
    }

    /**
     * @return array<string, array{mixed, float}>
     */
    public static function validTotalAmountProvider(): array
    {
        return [
            'numeric string' => ['5.25', 15.35],
            'integer' => [5, 15.10],
            'float' => [5.25, 15.35],
            'zero' => ['0.00', 10.10],
        ];
    }

    public function testGetMaxAmountIgnoresNonNumericTotals(): void
    {
        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->getMaxAmount(
                [['totalAmount' => 'abc'], ['totalAmount' => ['1']], 'item', ['totalAmount' => '5.50']],
                null
            )
        );

        $this->assertSame(5.5, $result);
    }

    /**
     * @dataProvider incompleteTransactionProvider
     *
     * @param array<string, mixed> $transaction
     */
    public function testRefundAllReturnsErrorForIncompleteTransaction(string $serviceName, array $transaction): void
    {
        $request = new Request([], ['orderItems' => [], 'customRefundAmount' => 50.00]);
        $order = $this->createOrder(100.00);
        $this->mockRefundableOrder($serviceName);
        $this->translator->method('trans')->willReturnArgument(0);

        $this->clientService->expects($this->never())->method('get');
        $this->stateTransitionService->expects($this->never())->method('transitionPaymentState');

        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->refundAll($request, $order, $this->context, [$transaction])
        );

        $this->assertSame([[
            'status' => false,
            'message' => 'buckaroo-payment.missing_transaction',
        ]], $result);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function incompleteTransactionProvider(): array
    {
        return [
            'missing transactions' => ['ideal', ['id' => 'trx-1', 'amount' => 50.00]],
            'empty transactions' => ['ideal', ['id' => 'trx-1', 'amount' => 50.00, 'transactions' => '']],
            'array transactions' => ['ideal', ['id' => 'trx-1', 'amount' => 50.00, 'transactions' => ['TRX123']]],
            'missing id' => ['ideal', ['amount' => 50.00, 'transactions' => 'TRX123']],
            'missing transactions and method' => ['paybybank', ['id' => 'trx-1', 'amount' => 50.00]],
        ];
    }

    public function testPayByBankRefundWithoutTransactionMethodKeepsPayByBank(): void
    {
        $request = new Request([], ['orderItems' => [], 'customRefundAmount' => 50.00]);
        $order = $this->createOrder(100.00);
        $this->mockRefundableOrder('paybybank');

        $client = $this->mockClient(true);
        $client->method('setPayload')->willReturnSelf();
        $client->expects($this->never())->method('setPaymentCode');
        $this->clientService->expects($this->once())->method('get')->with('paybybank')->willReturn($client);
        $this->translator->method('trans')->willReturnArgument(0);

        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->refundAll($request, $order, $this->context, [
                ['id' => 'trx-1', 'amount' => 50.00, 'transactions' => 'TRX123'],
            ])
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['status']);
    }

    public function testPayByBankRefundOfIdealTransactionUsesIdeal(): void
    {
        $request = new Request([], ['orderItems' => [], 'customRefundAmount' => 50.00]);
        $order = $this->createOrder(100.00);
        $this->mockRefundableOrder('paybybank');

        $client = $this->mockClient(true);
        $client->method('setPayload')->willReturnSelf();
        $client->expects($this->once())->method('setPaymentCode')->with('ideal')->willReturnSelf();
        $this->clientService->method('get')->willReturn($client);
        $this->translator->method('trans')->willReturnArgument(0);

        $result = $this->withoutPhpNotices(
            fn () => $this->refundService->refundAll($request, $order, $this->context, [
                ['id' => 'trx-1', 'amount' => 50.00, 'transactions' => 'TRX123', 'transaction_method' => 'ideal'],
            ])
        );

        $this->assertTrue($result[0]['status']);
    }

    public function testGetConfigCodeThrowsExceptionWhenServiceNameMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name is not a string');

        $this->withoutPhpNotices(fn () => $this->refundService->getConfigCode([]));
    }

    // Helper methods

    private function mockRefundableOrder(string $serviceName): void
    {
        $this->transactionService->method('isBuckarooPaymentMethod')->willReturn(true);
        $this->transactionService->method('getCustomFields')->willReturn([
            'serviceName' => $serviceName,
            'canRefund' => 1,
            'refunded' => 0,
            'originalTransactionKey' => 'abc123',
        ]);
        $this->settingsService->method('getParsedLabel')->willReturn('Refund');
    }

    /**
     * @return Client&MockObject
     */
    private function mockClient(bool $success): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn($success);
        $client->method('execute')->willReturn($response);

        return $client;
    }

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

    private function setupSuccessfulRefund(OrderEntity $order, float $amount): void
    {
        $client = $this->createMock(Client::class);
        $client->method('setAction')->willReturnSelf();
        $client->method('setPayload')->willReturnSelf();
        $client->method('setPaymentCode')->willReturnSelf();

        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('isSuccess')->willReturn(true);
        
        $client->method('execute')->willReturn($response);

        $this->clientService
            ->method('get')
            ->willReturn($client);

        $this->urlService
            ->method('getReturnUrl')
            ->willReturn('https://example.com/push');

        $this->settingsService
            ->method('getParsedLabel')
            ->willReturn('Refund');

        $this->translator
            ->method('trans')
            ->willReturn('Refunded successfully');
    }
}
