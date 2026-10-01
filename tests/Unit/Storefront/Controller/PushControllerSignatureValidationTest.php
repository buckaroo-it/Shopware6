<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Buckaroo signature is the only thing that authenticates a push. iDEAL Fast Checkout
 * used to be exempted from it, which meant a push could hand itself the exemption by
 * posting brq_SERVICE_ideal_TransactionFlow=Fast_Checkout and then rewrite the customer
 * behind any order it named. These tests pin the invariant: nothing at all happens to an
 * order, a transaction or a customer until validateSignature() has returned true.
 */
class PushControllerSignatureValidationTest extends TestCase
{
    private const SECRET_KEY = 'test-secret-key';

    private const SALES_CHANNEL_ID = 'sales-channel-id';

    private const COUNTRY_ID = 'country-id';

    /**
     * A Fast Checkout push carrying no signature at all must be rejected before it can
     * touch anything, however complete the rest of its parameters look.
     */
    public function testUnsignedFastCheckoutPushIsRejected(): void
    {
        $this->assertSignatureRejected($this->handlePush($this->fastCheckoutPushData()));
    }

    /**
     * Same for a Fast Checkout push carrying a signature that does not match the secret
     * key of the sales channel.
     */
    public function testInvalidlySignedFastCheckoutPushIsRejected(): void
    {
        $postData = $this->fastCheckoutPushData();
        $postData['brq_signature'] = 'forged-signature';

        $this->assertSignatureRejected($this->handlePush($postData));
    }

    /**
     * The attack this guards against: no signature, but the Fast Checkout service fields
     * of a real invoice filled in with the attacker's own contact details. Every
     * collaborator is a never() mock, so reaching updateIdealFastCheckout() - which would
     * overwrite the customer's name, e-mail and addresses - fails the test.
     */
    public function testUnsignedFastCheckoutPushCannotOverwriteCustomerDetails(): void
    {
        $postData = $this->fastCheckoutPushData();
        $postData['brq_SERVICE_ideal_ContactDetailsEmail'] = 'attacker@example.com';
        $postData['brq_SERVICE_ideal_ContactDetailsFirstName'] = 'Attacker';
        $postData['brq_SERVICE_ideal_InvoiceAddressStreet'] = 'Attacker Street 66';

        $this->assertSignatureRejected($this->handlePush($postData));
    }

    /**
     * An ordinary push without the Fast Checkout flow must still be rejected when it is
     * unsigned - the fix must not have narrowed the check to Fast Checkout pushes.
     */
    public function testUnsignedRegularPushIsRejected(): void
    {
        $this->assertSignatureRejected($this->handlePush($this->regularPushData()));
    }

    /**
     * A genuine Buckaroo Fast Checkout push - signed with the sales channel secret key
     * through the plugin's own signature implementation - still runs the Fast Checkout
     * customer update with the details from the push.
     */
    public function testSignedFastCheckoutPushUpdatesTheCustomer(): void
    {
        $order = $this->orderWithCustomer('customer-id');
        $customer = new CustomerEntity();
        $customer->setId('customer-id');

        $orderService = $this->createMock(OrderService::class);
        $orderService->method('setSaleChannelContext')->willReturnSelf();
        $orderService->method('getOrderById')->willReturn($order);
        $orderService->expects($this->once())
            ->method('updateOrderAddresses')
            ->with(
                $this->identicalTo($order),
                $this->callback(
                    static fn (array $billingData): bool => $billingData['firstName'] === 'Consumer'
                        && $billingData['street'] === 'Consumer Street 1'
                ),
                $this->isType('array'),
                self::COUNTRY_ID
            );

        $customerService = $this->createMock(CustomerService::class);
        $customerService->method('setSaleChannelContext')->willReturnSelf();
        $customerService->method('getCustomerById')->with('customer-id')->willReturn($customer);
        $customerService->expects($this->once())
            ->method('updateDummyCustomerFromPush')
            ->with(
                $this->identicalTo($order),
                $this->identicalTo($customer),
                $this->callback(
                    static fn (array $customerData): bool => $customerData['email'] === 'consumer@example.com'
                        && $customerData['first_name'] === 'Consumer'
                )
            );

        $this->handlePush(
            $this->signed($this->fastCheckoutPushData()),
            [
                'orderService' => $orderService,
                'customerService' => $customerService,
                'checkoutHelper' => $this->checkoutHelperWithoutOrder(),
                'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
            ]
        );
    }

    /**
     * A signed push without the Fast Checkout flow is processed as before and never runs
     * the Fast Checkout customer update. The order lookup returning null ends the request
     * with the existing payment error response, which is enough to show the push got past
     * signature validation and into normal processing.
     */
    public function testSignedRegularPushIsProcessedWithoutTheFastCheckoutUpdate(): void
    {
        $response = $this->handlePush(
            $this->signed($this->regularPushData()),
            [
                'checkoutHelper' => $this->checkoutHelperWithoutOrder(),
                'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
            ]
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * With no secret configured for the sales channel the signature is sha1(fields), which
     * anyone can compute. Such a push is rejected before anything is looked up.
     *
     * @dataProvider missingSecretKeyProvider
     */
    public function testPushToSalesChannelWithoutSecretKeyIsRejected(?string $secretKey): void
    {
        $this->assertSignatureRejected(
            $this->handlePush($this->signed($this->regularPushData(), ''), [], $secretKey)
        );
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function missingSecretKeyProvider(): array
    {
        return [
            'not configured' => [null],
            'empty' => [''],
        ];
    }

    /**
     * A push validly signed for this sales channel cannot settle an order of another sales
     * channel, a transaction of another order, or an order in another currency. It is
     * rejected before it is claimed or acted on: every other collaborator is untouchable.
     *
     * @dataProvider unboundOrderProvider
     *
     * @param array<string, string> $overrides
     */
    public function testSignedPushNotBoundToItsOrderIsRejected(OrderEntity $order, array $overrides): void
    {
        $response = $this->handlePush(
            $this->signed($overrides + $this->regularPushData()),
            ['checkoutHelper' => $this->checkoutHelperFindingOnly($order)]
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * @return array<string, array{OrderEntity, array<string, string>}>
     */
    public static function unboundOrderProvider(): array
    {
        return [
            'order of another sales channel' => [self::boundOrder('other-sales-channel-id'), []],
            'transaction not on the order' => [self::boundOrder(self::SALES_CHANNEL_ID, 'other-transaction-id'), []],
            'currency does not match the order' => [self::boundOrder(), ['brq_currency' => 'USD']],
        ];
    }

    /**
     * The binding check accepts the genuine push: same sales channel, its own transaction
     * and the order currency (compared case-insensitively). Reaching the authorize handling
     * shows the push got past the binding check.
     */
    public function testSignedPushBoundToItsOrderIsProcessed(): void
    {
        $stateTransitionService = $this->createMock(StateTransitionService::class);
        $stateTransitionService->expects($this->once())->method('isTransitionPaymentState')->willReturn(true);

        $response = $this->handlePush(
            $this->signed(['brq_currency' => 'eur', 'brq_transaction_type' => 'I872'] + $this->regularPushData()),
            [
                'stateTransitionService' => $stateTransitionService,
                'checkoutHelper' => $this->checkoutHelperWithoutOrder(),
                'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
            ]
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * The order-number fallback only looks in the sales channel the push was posted to.
     */
    public function testOrderNumberFallbackIsScopedToTheSalesChannel(): void
    {
        $postData = $this->regularPushData();
        unset($postData['ADD_orderId'], $postData['ADD_orderTransactionId']);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->once())
            ->method('getOrderByOrderNumber')
            ->with('INV-001', $this->isInstanceOf(Context::class), self::SALES_CHANNEL_ID)
            ->willReturn(null);

        $response = $this->handlePush($this->signed($postData), ['checkoutHelper' => $checkoutHelper]);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    private function assertSignatureRejected(JsonResponse $response): void
    {
        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.signatureIncorrect'],
            $this->decode($response),
            'an unsigned or invalidly signed push must return the signature error response'
        );
    }

    /**
     * A Fast Checkout push with everything else the controller needs to reach
     * updateIdealFastCheckout(): the order and transaction it refers to, and the iDEAL
     * service fields the customer details are taken from.
     *
     * @return array<string, string>
     */
    private function fastCheckoutPushData(): array
    {
        return $this->regularPushData() + [
            'brq_SERVICE_ideal_TransactionFlow' => 'Fast_Checkout',
            'brq_SERVICE_ideal_ContactDetailsFirstName' => 'Consumer',
            'brq_SERVICE_ideal_ContactDetailsLastName' => 'Example',
            'brq_SERVICE_ideal_ContactDetailsEmail' => 'consumer@example.com',
            'brq_SERVICE_ideal_InvoiceAddressFirstName' => 'Consumer',
            'brq_SERVICE_ideal_InvoiceAddressLastName' => 'Example',
            'brq_SERVICE_ideal_InvoiceAddressStreet' => 'Consumer Street 1',
            'brq_SERVICE_ideal_InvoiceAddressPostalCode' => '1234AB',
            'brq_SERVICE_ideal_InvoiceAddressCity' => 'Amsterdam',
            'brq_SERVICE_ideal_InvoiceAddressCompanyName' => 'Consumer BV',
            'brq_SERVICE_ideal_ShippingAddressFirstName' => 'Consumer',
            'brq_SERVICE_ideal_ShippingAddressLastName' => 'Example',
            'brq_SERVICE_ideal_ShippingAddressStreet' => 'Consumer Street 1',
            'brq_SERVICE_ideal_ShippingAddressPostalCode' => '1234AB',
            'brq_SERVICE_ideal_ShippingAddressCity' => 'Amsterdam',
            'brq_SERVICE_ideal_ShippingAddressCompanyName' => 'Consumer BV',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function regularPushData(): array
    {
        return [
            'brq_statuscode' => '190',
            'brq_amount' => '10.00',
            'brq_invoicenumber' => 'INV-001',
            'brq_transaction_method' => 'ideal',
            'ADD_orderId' => 'order-id',
            'ADD_orderTransactionId' => 'order-transaction-id',
        ];
    }

    /**
     * Sign the push the way Buckaroo does. The signature algorithm itself lives in the
     * Buckaroo PHP SDK (the single source of truth); this mirrors it over the signed
     * fields so the controller flow can be exercised with a genuinely valid signature.
     *
     * @param array<string, string> $postData
     *
     * @return array<string, string>
     */
    private function signed(array $postData, string $secretKey = self::SECRET_KEY): array
    {
        $data = array_filter($postData, static function ($key): bool {
            $key = strtolower((string) $key);

            return $key !== 'brq_signature'
                && in_array(explode('_', $key)[0], ['brq', 'add', 'cust'], true);
        }, ARRAY_FILTER_USE_KEY);

        uksort($data, static function ($a, $b): int {
            return strcmp(strtolower((string) $a), strtolower((string) $b));
        });

        $signatureString = '';
        foreach ($data as $key => $value) {
            $signatureString .= $key . '=' . html_entity_decode((string) $value);
        }
        $signatureString .= $secretKey;

        $postData['brq_signature'] = sha1($signatureString);

        return $postData;
    }

    private function signatureValidationService(?string $secretKey = self::SECRET_KEY): SignatureValidationService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->with('secretKey', self::SALES_CHANNEL_ID)
            ->willReturn($secretKey);

        return new SignatureValidationService($settingsService, $this->createMock(LoggerInterface::class));
    }

    /**
     * Run the push action. Every collaborator the push must not be able to reach while it
     * is unauthenticated is mocked with a never() expectation; overriding one is how a
     * test says "this push is allowed to get this far".
     *
     * @param array<string, string> $postData
     * @param array<string, object> $services
     * @param string|null $secretKey the secret key configured for the sales channel
     */
    private function handlePush(
        array $postData,
        array $services = [],
        ?string $secretKey = self::SECRET_KEY
    ): JsonResponse {
        /** @var TransactionService $transactionService */
        $transactionService = $services['transactionService'] ?? $this->untouchable(TransactionService::class);
        /** @var StateTransitionService $stateTransitionService */
        $stateTransitionService = $services['stateTransitionService']
            ?? $this->untouchable(StateTransitionService::class);
        /** @var InvoiceService $invoiceService */
        $invoiceService = $services['invoiceService'] ?? $this->untouchable(InvoiceService::class);
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $services['checkoutHelper'] ?? $this->untouchable(CheckoutHelper::class);
        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $services['eventDispatcher'] ?? $this->untouchable(EventDispatcherInterface::class);
        /** @var IdealQrOrderRepository $idealQrRepository */
        $idealQrRepository = $services['idealQrRepository'] ?? $this->untouchable(IdealQrOrderRepository::class);
        /** @var OrderService $orderService */
        $orderService = $services['orderService'] ?? $this->untouchable(OrderService::class);
        /** @var CustomerService $customerService */
        $customerService = $services['customerService'] ?? $this->untouchable(CustomerService::class);

        $controller = new PushController(
            $this->signatureValidationService($secretKey),
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

        return $controller->pushBuckaroo(new Request([], $postData), $this->salesChannelContext());
    }

    /**
     * A mock that fails the test as soon as any method on it is called.
     *
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
     * Finds the order when the push is bound to it, then no longer, so processing ends with
     * the payment error response right after the claim and Fast Checkout update.
     */
    private function checkoutHelperWithoutOrder(): CheckoutHelper
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')->willReturnOnConsecutiveCalls(self::boundOrder(), null);

        return $checkoutHelper;
    }

    private function checkoutHelperFindingOnly(OrderEntity $order): CheckoutHelper
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->once())->method('getOrderById')->with('order-id')->willReturn($order);
        $checkoutHelper->expects($this->never())
            ->method($this->logicalNot($this->equalTo('getOrderById')));

        return $checkoutHelper;
    }

    /**
     * The order the regular push refers to, in the sales channel the push is posted to.
     */
    private static function boundOrder(
        string $salesChannelId = self::SALES_CHANNEL_ID,
        string $transactionId = 'order-transaction-id',
        string $currencyIso = 'EUR'
    ): OrderEntity {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId($transactionId);

        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode($currencyIso);

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId($salesChannelId);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));
        $order->setCurrency($currency);

        return $order;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SALES_CHANNEL_ID);
        $salesChannel->setCountryId(self::COUNTRY_ID);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $salesChannelContext;
    }

    private function orderWithCustomer(string $customerId): OrderEntity
    {
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId('order-customer-id');
        $orderCustomer->setCustomerId($customerId);

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setOrderCustomer($orderCustomer);

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$response->getContent(), true);

        return $decoded;
    }
}
