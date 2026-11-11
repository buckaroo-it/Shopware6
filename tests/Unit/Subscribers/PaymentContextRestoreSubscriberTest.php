<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentContextCookieSubscriber;
use Buckaroo\Shopware6\Subscribers\PaymentContextRestoreSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PaymentContextRestoreSubscriberTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const OTHER_TOKEN = 'ffffffffffffffffffffffffffffffff';
    private const SALES_CHANNEL_ID = '57480b67bf5949bb9c333671b2c0e51b';

    private PaymentContextRestoreSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PaymentContextRestoreSubscriber();
    }

    /**
     * @dataProvider restoreRouteProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('restoreRouteProvider')]
    public function testItRestoresTheTokenFromTheCookieOnPaymentReturnRoutes(string $route): void
    {
        $request = $this->createRequest($route, self::TOKEN);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
        $this->assertSame(self::TOKEN, $request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function restoreRouteProvider(): array
    {
        return [
            'finalize transaction' => ['payment.finalize.transaction'],
            'checkout finish' => ['frontend.checkout.finish.page'],
            'buckaroo cancel' => ['frontend.action.buckaroo.cancel'],
        ];
    }

    public function testItNeverTakesTheTokenFromTheUrlOrBody(): void
    {
        $request = new Request(
            ['sw-context-token' => self::TOKEN, 'add_sw-context-token' => self::TOKEN],
            ['sw-context-token' => self::TOKEN, 'add_sw-context-token' => self::TOKEN]
        );
        $request->attributes->set('_route', 'payment.finalize.transaction');

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
        $this->assertNull($request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItIgnoresOtherRoutes(): void
    {
        $request = $this->createRequest('frontend.detail.page', self::TOKEN);

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
        $this->assertNull($request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItDoesNotReplaceTheContextOfALiveSession(): void
    {
        $request = $this->createRequest('frontend.checkout.finish.page', self::TOKEN);
        $session = $this->attachSession($request, true);
        $session->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::OTHER_TOKEN);

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
        $this->assertSame(self::OTHER_TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItRestoresWhenTheLiveSessionAlreadyHoldsTheSameToken(): void
    {
        $request = $this->createRequest('frontend.checkout.finish.page', self::TOKEN);
        $this->attachSession($request, true)->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::TOKEN);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
    }

    public function testItRestoresWhenTheSessionCookieWasNotSent(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', self::TOKEN);
        $session = $this->attachSession($request, false);
        $session->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::OTHER_TOKEN);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    /**
     * With isCustomerBoundToSalesChannel enabled, Shopware renews a session token whose session has
     * no (or another) sales channel, which logged the customer out on /checkout/finish.
     */
    public function testItStoresTheSalesChannelOfTheRequestInTheSession(): void
    {
        $request = $this->createRequest('frontend.checkout.finish.page', self::TOKEN);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, self::SALES_CHANNEL_ID);
        $session = $this->attachSession($request, false);

        $this->dispatch($request);

        $this->assertSame(self::SALES_CHANNEL_ID, $session->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID));
        // Shopware 6.7.8+ reads the token per sales channel when customers are bound to one
        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN . '-' . self::SALES_CHANNEL_ID));
    }

    public function testItTakesTheSalesChannelFromThePersistedContextOnFinalize(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('sales_channel_api_context'), ['token' => self::TOKEN])
            ->willReturn(self::SALES_CHANNEL_ID);

        $this->subscriber = new PaymentContextRestoreSubscriber($connection);

        $request = $this->createRequest('payment.finalize.transaction', self::TOKEN);
        $session = $this->attachSession($request, false);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
        $this->assertSame(self::SALES_CHANNEL_ID, $session->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID));
        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN . '-' . self::SALES_CHANNEL_ID));
    }

    public function testItLeavesTheSalesChannelUnsetWhenItCannotBeResolved(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $this->subscriber = new PaymentContextRestoreSubscriber($connection);

        $request = $this->createRequest('payment.finalize.transaction', self::TOKEN);
        $session = $this->attachSession($request, false);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
        $this->assertFalse($session->has(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID));
    }

    public function testItDoesNothingWithoutTheCookie(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', null);

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
    }

    public function testItIgnoresSubRequests(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', self::TOKEN);

        $this->dispatch($request, HttpKernelInterface::SUB_REQUEST);

        $this->assertNull($request->attributes->get('sw-context-token'));
    }

    private function createRequest(string $route, ?string $cookieToken): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        if ($cookieToken !== null) {
            $request->cookies->set(PaymentContextCookieSubscriber::COOKIE_NAME, $cookieToken);
        }

        return $request;
    }

    private function attachSession(Request $request, bool $cookieSent): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setName('session-');
        $session->start();
        $request->setSession($session);

        if ($cookieSent) {
            $request->cookies->set('session-', $session->getId());
        }

        return $session;
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);

        $this->subscriber->onKernelRequest($event);
    }
}
