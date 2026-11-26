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
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Buckaroo subscription invoice pushes (brq_Type RegularInvoice) carry no mutation type,
 * transaction type, transaction key or status code. They must still reach PushProcessingEvent,
 * whose listeners (the Subscriptions plugin) create the recurring order and mark it paid.
 */
class PushControllerInvoicePushTest extends TestCase
{
    private const TRANSACTION_ID = 'order-transaction-id';

    private const INVOICE_PUSH_KEY = 'INVOICE|A85A6F4C084B4F1D9E1A889456AE2FFE|CHANGEDSTATUS|2026-11-10T00:08:45.6713075+01:00|10';

    public function testInvoicePushReachesThePushProcessingEvent(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with(self::INVOICE_PUSH_KEY, self::TRANSACTION_ID)
            ->willReturn(true);
        $processedPushService->expects($this->never())->method('release');

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (PushProcessingEvent $event) use (&$dispatched): object {
                $dispatched[] = $event->getRequest()->request->get('brq_InvoiceNumber');

                return $event->setCanContinue(false);
            }
        );

        $response = $this->handlePush($this->invoicePushData(), [
            'processedPushService' => $processedPushService,
            'eventDispatcher' => $eventDispatcher,
        ]);

        $this->assertSame(['00003728'], $dispatched, 'the invoice push reaches the listeners');
        $this->assertSame(
            ['status' => true, 'message' => 'buckaroo.messages.pushInterrupted'],
            $this->decode($response)
        );
    }

    /**
     * Without a listener taking over, an invoice push must not be processed as a payment push.
     */
    public function testInvoicePushIsNotProcessedAsAPayment(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->method('claim')->willReturn(true);
        $processedPushService->expects($this->never())->method('release');

        $response = $this->handlePush($this->invoicePushData(), [
            'processedPushService' => $processedPushService,
            'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
        ]);

        $this->assertSame(
            ['status' => true, 'message' => 'buckaroo.messages.pushInterrupted'],
            $this->decode($response)
        );
    }

    /**
     * A listener failing leaves the invoice event unhandled: its claim is released so that
     * Buckaroo's retry is processed.
     */
    public function testClaimIsReleasedWhenTheInvoiceListenerFails(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->method('claim')->willReturn(true);
        $processedPushService->expects($this->once())->method('release')->with(self::INVOICE_PUSH_KEY);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willThrowException(new \RuntimeException('listener failed'));

        $this->expectException(\RuntimeException::class);

        $this->handlePush($this->invoicePushData(), [
            'processedPushService' => $processedPushService,
            'eventDispatcher' => $eventDispatcher,
        ]);
    }

    public function testRetryOfTheSameInvoiceEventIsNotProcessedAgain(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with(self::INVOICE_PUSH_KEY, self::TRANSACTION_ID)
            ->willReturn(false);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.pushAlreadySend'],
            $this->decode($this->handlePush($this->invoicePushData(), ['processedPushService' => $processedPushService]))
        );
    }

    /**
     * The paid push of an invoice is a later event of the same invoice; it must not be taken
     * for a retry of the invoice-created push.
     */
    public function testLaterEventOfTheSameInvoiceIsClaimedSeparately(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with(
                'INVOICE|A85A6F4C084B4F1D9E1A889456AE2FFE|CHANGEDTRANSACTIONSTATUS|2026-11-12T23:04:27.1000000+01:00|20',
                self::TRANSACTION_ID
            )
            ->willReturn(false);

        $postData = $this->invoicePushData([
            'brq_Event' => 'ChangedTransactionStatus',
            'brq_EventDateTime' => '2026-11-12T23:04:27.1000000+01:00',
            'brq_InvoiceStatusCode' => '20',
            'brq_IsPaid' => 'true',
        ]);

        $this->handlePush($postData, ['processedPushService' => $processedPushService]);
    }

    public function testInvoicePushWithoutEventTimeIsRejected(): void
    {
        $postData = $this->invoicePushData();
        unset($postData['brq_EventDateTime']);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($this->handlePush($postData, ['checkoutHelper' => $this->untouchable(CheckoutHelper::class)]))
        );
    }

    /**
     * Field set of a real RegularInvoice push (values anonymised).
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function invoicePushData(array $overrides = []): array
    {
        return $overrides + [
            'brq_InvoiceKey' => 'A85A6F4C084B4F1D9E1A889456AE2FFE',
            'brq_InvoiceNumber' => '00003728',
            'brq_WebsiteKey' => 'website-key',
            'brq_DebtorCode' => 'debtor-code',
            'brq_SchemeKey' => 'DefaultNone',
            'brq_IsTest' => 'false',
            'brq_Type' => 'RegularInvoice',
            'brq_InvoiceStatusCode' => '10',
            'brq_Event' => 'ChangedStatus',
            'brq_EventCategory' => 'FinancialChange',
            'brq_EventDateTime' => '2026-11-10T00:08:45.6713075+01:00',
            'brq_Currency' => 'EUR',
            'brq_AmountDebit' => '59.99',
            'brq_IsPaid' => 'false',
            'brq_EventParameters_StatusCode' => '10',
            'brq_AdditionalParameters_orderId' => 'order-id',
            'brq_AdditionalParameters_orderTransactionId' => self::TRANSACTION_ID,
            'brq_AdditionalParameters_SubscriptionGuid' => 'SUBSCRIPTION-GUID',
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
        $checkoutHelper->method('getSettingsValue')
            ->with('websiteKey', 'sales-channel-id')
            ->willReturn('website-key');
        $checkoutHelper->expects($this->never())
            ->method($this->logicalNot($this->logicalOr(
                $this->equalTo('getOrderById'),
                $this->equalTo('getSettingsValue')
            )));

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

        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

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
