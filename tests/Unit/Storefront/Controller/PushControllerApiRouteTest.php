<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\ProcessedPushService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Orders of API (headless) sales channels cannot receive a push on the storefront push
 * route: Shopware only maps storefront-type domains to a request, so the request is
 * answered with "Domain Mapping Misconfiguration" before the controller runs, and the
 * domains of a headless sales channel point at an external frontend anyway. The API push
 * route under /api is reachable without domain mapping and takes the sales channel from
 * the URL the plugin generated for the order.
 *
 * These tests pin that the route is registered outside domain mapping, that the push is
 * authenticated against the secret key of the sales channel named in the URL before
 * anything else happens, and that the order named in the push must belong to that sales
 * channel.
 */
class PushControllerApiRouteTest extends TestCase
{
    private const SECRET_KEY = 'headless-secret-key';

    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    private const PUSH_KEY = 'KEY-123|190|C021|PROCESSING';

    public function testApiPushRouteIsRegisteredOutsideStorefrontDomainMapping(): void
    {
        $route = $this->apiRoute();

        $this->assertSame(UrlService::API_PUSH_PATH . '/{salesChannelId}', $route->getPath());
        $this->assertStringStartsWith('/api/', (string)$route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertSame('api.buckaroo.push', $route->getName());

        $defaults = $route->getDefaults();
        $this->assertSame(['api'], $defaults['_routeScope'] ?? null, 'the route must have the api scope');
        $this->assertFalse(
            $defaults['auth_required'] ?? true,
            'the Buckaroo signature authenticates the push, not an admin API token'
        );
        $this->assertArrayNotHasKey('_acl', $defaults);

        $requirements = $route->getRequirements();
        $this->assertArrayHasKey('salesChannelId', $requirements);
        $this->assertMatchesRegularExpression('/^' . $requirements['salesChannelId'] . '$/', self::SALES_CHANNEL_ID);
        $this->assertDoesNotMatchRegularExpression('/^' . $requirements['salesChannelId'] . '$/', '../push');
    }

    /**
     * The push is processed in the sales channel named in the URL: its secret key verifies
     * the signature, and the order it names - which belongs to that sales channel - is
     * claimed and processed. Reaching the claim shows the push got past the signature and
     * binding checks, exactly as a push on the storefront route does.
     */
    public function testSignedApiPushIsProcessedInTheSalesChannelOfTheUrl(): void
    {
        $processedPushService = $this->createMock(ProcessedPushService::class);
        $processedPushService->expects($this->once())
            ->method('claim')
            ->with(self::PUSH_KEY, 'order-transaction-id')
            ->willReturn(true);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('getOrderById')
            ->with('order-id')
            ->willReturnOnConsecutiveCalls($this->boundOrder(self::SALES_CHANNEL_ID), null);

        $response = $this->pushToApiRoute(
            $this->signed($this->pushData()),
            strtoupper(self::SALES_CHANNEL_ID),
            [
                'checkoutHelper' => $checkoutHelper,
                'processedPushService' => $processedPushService,
                'eventDispatcher' => $this->createMock(EventDispatcherInterface::class),
            ]
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response),
            'processing continued past the claim and ended on the second (null) order lookup'
        );
    }

    /**
     * Naming a sales channel in the URL gains nothing without its secret key: an unsigned
     * push is rejected before any collaborator is touched.
     */
    public function testUnsignedApiPushIsRejected(): void
    {
        $response = $this->pushToApiRoute($this->pushData(), self::SALES_CHANNEL_ID);

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.signatureIncorrect'],
            $this->decode($response)
        );
    }

    /**
     * A push signed with the secret of another sales channel is rejected: the sales channel
     * in the URL decides which secret key is used.
     */
    public function testApiPushSignedWithAnotherSecretIsRejected(): void
    {
        $response = $this->pushToApiRoute(
            $this->signed($this->pushData(), 'another-secret-key'),
            self::SALES_CHANNEL_ID
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.signatureIncorrect'],
            $this->decode($response)
        );
    }

    /**
     * The order named in the push must belong to the sales channel named in the URL. A push
     * for an order of another sales channel - even one signed with the URL sales channel's
     * secret - is rejected before it is claimed.
     */
    public function testApiPushForAnOrderOfAnotherSalesChannelIsRejected(): void
    {
        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->expects($this->once())
            ->method('getOrderById')
            ->with('order-id')
            ->willReturn($this->boundOrder('other-sales-channel-id'));

        $response = $this->pushToApiRoute(
            $this->signed($this->pushData()),
            self::SALES_CHANNEL_ID,
            ['checkoutHelper' => $checkoutHelper]
        );

        $this->assertSame(
            ['status' => false, 'message' => 'buckaroo.messages.paymentError'],
            $this->decode($response)
        );
    }

    /**
     * The sales channel context is created for the sales channel of the URL (normalised to
     * lower case), with a token of its own so no shopper session is touched, and before
     * anything from the push body is used - the body may be unsigned garbage at that point.
     */
    public function testSalesChannelContextIsCreatedForTheSalesChannelOfTheUrlOnly(): void
    {
        $salesChannelContextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $salesChannelContextService->expects($this->once())
            ->method('get')
            ->with($this->callback(
                static fn (SalesChannelContextServiceParameters $parameters): bool =>
                    $parameters->getSalesChannelId() === self::SALES_CHANNEL_ID
                    && str_starts_with($parameters->getToken(), 'buckaroo-push-')
                    && strlen($parameters->getToken()) === strlen('buckaroo-push-') + 32
                    && $parameters->getLanguageId() === null
                    && $parameters->getCurrencyId() === null
                    && $parameters->getOriginalContext() instanceof Context
            ))
            ->willReturn($this->salesChannelContext());

        $this->pushToApiRoute(
            ['garbage' => 'value'],
            strtoupper(self::SALES_CHANNEL_ID),
            ['salesChannelContextService' => $salesChannelContextService]
        );
    }

    private function apiRoute(): Route
    {
        $method = new \ReflectionMethod(PushController::class, 'pushBuckarooApi');
        $attributes = $method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);
        $this->assertCount(1, $attributes, 'pushBuckarooApi() must carry exactly one Route attribute');

        /** @var Route $route */
        $route = $attributes[0]->newInstance();

        return $route;
    }

    /**
     * @param array<string, string> $postData
     * @param array<string, object> $services
     */
    private function pushToApiRoute(array $postData, string $salesChannelIdInUrl, array $services = []): JsonResponse
    {
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $services['checkoutHelper'] ?? $this->untouchable(CheckoutHelper::class);
        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $services['eventDispatcher'] ?? $this->untouchable(EventDispatcherInterface::class);
        /** @var ProcessedPushService $processedPushService */
        $processedPushService = $services['processedPushService']
            ?? $this->untouchable(ProcessedPushService::class);
        /** @var SalesChannelContextServiceInterface $salesChannelContextService */
        $salesChannelContextService = $services['salesChannelContextService']
            ?? $this->salesChannelContextService();

        $controller = new PushController(
            $this->signatureValidationService(),
            $this->untouchable(TransactionService::class),
            $this->untouchable(StateTransitionService::class),
            $this->untouchable(InvoiceService::class),
            $checkoutHelper,
            $this->createMock(LoggerInterface::class),
            $eventDispatcher,
            $this->untouchable(IdealQrOrderRepository::class),
            $this->untouchable(OrderService::class),
            $this->untouchable(CustomerService::class),
            $processedPushService,
            $salesChannelContextService
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        return $controller->pushBuckarooApi(
            $salesChannelIdInUrl,
            new Request([], $postData),
            Context::createDefaultContext()
        );
    }

    /**
     * Resolves the sales channel of the URL to a context of that sales channel, as the
     * Shopware sales channel context service does for an existing sales channel.
     */
    private function salesChannelContextService(): SalesChannelContextServiceInterface
    {
        $salesChannelContextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $salesChannelContextService->method('get')
            ->with($this->callback(
                static fn (SalesChannelContextServiceParameters $parameters): bool =>
                    $parameters->getSalesChannelId() === self::SALES_CHANNEL_ID
            ))
            ->willReturn($this->salesChannelContext());

        return $salesChannelContextService;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SALES_CHANNEL_ID);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return $salesChannelContext;
    }

    /**
     * The secret key is looked up for the sales channel of the URL and no other.
     */
    private function signatureValidationService(): SignatureValidationService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->with('secretKey', self::SALES_CHANNEL_ID)
            ->willReturn(self::SECRET_KEY);

        return new SignatureValidationService($settingsService, $this->createMock(LoggerInterface::class));
    }

    /**
     * @return array<string, string>
     */
    private function pushData(): array
    {
        return [
            'brq_statuscode' => '190',
            'brq_amount' => '10.00',
            'brq_invoicenumber' => 'INV-001',
            'brq_transaction_method' => 'ideal',
            'brq_transaction_type' => 'C021',
            'brq_mutationtype' => 'Processing',
            'brq_transactions' => 'key-123',
            'ADD_orderId' => 'order-id',
            'ADD_orderTransactionId' => 'order-transaction-id',
        ];
    }

    /**
     * Sign the push the way Buckaroo does (mirrors the SDK algorithm over the signed fields).
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

    private function boundOrder(string $salesChannelId): OrderEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('order-transaction-id');

        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setSalesChannelId($salesChannelId);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));
        $order->setCurrency($currency);

        return $order;
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
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$response->getContent(), true);

        return $decoded;
    }
}
