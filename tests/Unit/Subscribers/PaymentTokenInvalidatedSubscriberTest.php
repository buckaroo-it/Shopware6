<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentTokenInvalidatedSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The subscriber runs on kernel.exception for a request Shopware has already resolved a
 * context for, so the order transaction lookup has to reuse that context instead of
 * creating one. These tests pin the propagation and the fallback when the request really
 * carries no context at all.
 */
class PaymentTokenInvalidatedSubscriberTest extends TestCase
{
    private const TRANSACTION_ID = '0191d6cb27ee7f2ea0e1b3c4a5d6e7f8';
    private const LANGUAGE_ID    = '0191d6cb27ee7f2ea0e1b3c4a5d6e701';
    private const FINISH_URL     = '/checkout/finish?orderId=abc';
    private const ERROR_URL      = '/account/order/edit/abc';

    /** @var EntityRepository&MockObject */
    private EntityRepository $orderTransactionRepository;

    /** @var UrlGeneratorInterface&MockObject */
    private UrlGeneratorInterface $urlGenerator;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private PaymentTokenInvalidatedSubscriber $subscriber;

    /** @var array<int, Context> */
    private array $usedContexts = [];

    protected function setUp(): void
    {
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);
        $this->urlGenerator               = $this->createMock(UrlGeneratorInterface::class);
        $this->logger                     = $this->createMock(LoggerInterface::class);
        $this->usedContexts               = [];

        $this->subscriber = new PaymentTokenInvalidatedSubscriber(
            $this->orderTransactionRepository,
            $this->urlGenerator,
            $this->logger
        );
    }

    public function testSubscribesToKernelException(): void
    {
        $this->assertArrayHasKey('kernel.exception', PaymentTokenInvalidatedSubscriber::getSubscribedEvents());
    }

    /**
     * The context the routing layer resolved for the request is the caller context, so it is
     * the one the transaction lookup must run on.
     */
    public function testUsesTheRequestContextForTheTransactionLookup(): void
    {
        $context = $this->createContext();
        $this->stubTransactionState('paid');

        $request = $this->createRequest();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $context);

        $event = $this->dispatch($request);

        $this->assertCount(1, $this->usedContexts);
        $this->assertSame($context, $this->usedContexts[0]);
        $this->assertRedirectsTo('https://shop.com' . self::FINISH_URL, $event);
    }

    /**
     * Storefront requests carry the SalesChannelContext; its Context keeps the sales channel,
     * language and permission scope of the customer who started the checkout.
     */
    public function testFallsBackToTheSalesChannelContextOnTheRequest(): void
    {
        $context = $this->createContext();

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn($context);

        $this->stubTransactionState('paid');

        $request = $this->createRequest();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);

        $event = $this->dispatch($request);

        $this->assertCount(1, $this->usedContexts);
        $this->assertSame($context, $this->usedContexts[0]);
        $this->assertRedirectsTo('https://shop.com' . self::FINISH_URL, $event);
    }

    /**
     * An unsuccessful transaction still redirects to the error URL from the token claims -
     * the state lookup must keep driving that decision.
     */
    public function testRedirectsToTheErrorUrlWhenTheTransactionIsNotSuccessful(): void
    {
        $this->stubTransactionState('open');

        $request = $this->createRequest();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $this->createContext());

        $event = $this->dispatch($request);

        $this->assertRedirectsTo('https://shop.com' . self::ERROR_URL, $event);
    }

    /**
     * No context on the request means no caller context exists. Rather than inventing one,
     * the state lookup is skipped and the redirect falls back on the token claims - the same
     * behaviour the subscriber already had when the lookup failed.
     */
    public function testSkipsTheTransactionLookupWhenTheRequestHasNoContext(): void
    {
        $this->orderTransactionRepository
            ->expects($this->never())
            ->method('search');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning');

        $event = $this->dispatch($this->createRequest());

        $this->assertRedirectsTo('https://shop.com' . self::FINISH_URL, $event);
    }

    /**
     * Unrelated exceptions must be left untouched.
     */
    public function testIgnoresOtherExceptions(): void
    {
        $request = $this->createRequest();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $this->createContext());

        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('something else')
        );

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    private function dispatch(Request $request): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $this->createTokenInvalidatedException()
        );

        $this->subscriber->onKernelException($event);

        return $event;
    }

    private function assertRedirectsTo(string $expectedUrl, ExceptionEvent $event): void
    {
        $response = $event->getResponse();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($expectedUrl, $response->getTargetUrl());
    }

    private function stubTransactionState(string $technicalName): void
    {
        $state = new StateMachineStateEntity();
        $state->setId(self::TRANSACTION_ID);
        $state->setUniqueIdentifier(self::TRANSACTION_ID);
        $state->setTechnicalName($technicalName);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setUniqueIdentifier(self::TRANSACTION_ID);
        $transaction->setStateMachineState($state);

        $this->orderTransactionRepository
            ->method('search')
            ->willReturnCallback(
                function (Criteria $criteria, Context $context) use ($transaction): EntitySearchResult {
                    $this->usedContexts[] = $context;

                    return new EntitySearchResult(
                        'order_transaction',
                        1,
                        new OrderTransactionCollection([$transaction]),
                        null,
                        $criteria,
                        $context
                    );
                }
            );
    }

    private function createRequest(): Request
    {
        $request = Request::create(
            'https://shop.com/payment/finalize-transaction?_sw_payment_token=' . $this->createPaymentToken()
        );
        $request->server->set('HTTP_HOST', 'shop.com');

        return $request;
    }

    /**
     * A structurally valid JWT: the subscriber only reads the plaintext claims, the signature
     * was already verified by Shopware before the token was rejected as consumed.
     */
    private function createPaymentToken(): string
    {
        $encode = static function (array $data): string {
            $json = json_encode($data, JSON_THROW_ON_ERROR);

            return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        };

        return implode('.', [
            $encode(['typ' => 'JWT', 'alg' => 'RS256']),
            $encode([
                'sub' => self::TRANSACTION_ID,
                'ful' => self::FINISH_URL,
                'eul' => self::ERROR_URL,
            ]),
            'not-verified-here',
        ]);
    }

    private function createTokenInvalidatedException(): \Throwable
    {
        return new class ('The provided token is invalidated.') extends \RuntimeException {
            public function getErrorCode(): string
            {
                return 'CHECKOUT__PAYMENT_TOKEN_INVALIDATED';
            }
        };
    }

    private function createContext(): Context
    {
        return new Context(new SystemSource(), [], Defaults::CURRENCY, [self::LANGUAGE_ID]);
    }
}
