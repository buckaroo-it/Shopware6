<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentContextRestoreSubscriber;
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

    private PaymentContextRestoreSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PaymentContextRestoreSubscriber();
    }

    /**
     * @dataProvider restoreRouteProvider
     */
    public function testItRestoresTheTokenOnPaymentReturnRoutes(string $route): void
    {
        $request = $this->createRequest($route, ['sw-context-token' => self::TOKEN]);

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

    public function testItRestoresThePrefixedPostParameter(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', [], ['add_sw-context-token' => self::TOKEN]);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
    }

    public function testItIgnoresOtherRoutesEvenWhenThePathContainsPayment(): void
    {
        $request = $this->createRequest('frontend.detail.page', ['sw-context-token' => self::TOKEN]);
        $request->server->set('REQUEST_URI', '/payment-terminal/p/123');

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
        $this->assertNull($request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItDoesNotReplaceTheContextOfALiveSession(): void
    {
        $request = $this->createRequest('frontend.checkout.finish.page', ['sw-context-token' => self::TOKEN]);
        $session = $this->attachSession($request, true);
        $session->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::OTHER_TOKEN);

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
        $this->assertSame(self::OTHER_TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItRestoresWhenTheLiveSessionAlreadyHoldsTheSameToken(): void
    {
        $request = $this->createRequest('frontend.checkout.finish.page', ['sw-context-token' => self::TOKEN]);
        $this->attachSession($request, true)->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::TOKEN);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
    }

    public function testItRestoresWhenTheSessionCookieWasNotSent(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', ['sw-context-token' => self::TOKEN]);
        // A fresh session started by the storefront because the cookie was lost on the cross-site return
        $session = $this->attachSession($request, false);
        $session->set(PlatformRequest::HEADER_CONTEXT_TOKEN, self::OTHER_TOKEN);

        $this->dispatch($request);

        $this->assertSame(self::TOKEN, $request->attributes->get('sw-context-token'));
        $this->assertSame(self::TOKEN, $session->get(PlatformRequest::HEADER_CONTEXT_TOKEN));
    }

    public function testItDoesNothingWithoutAToken(): void
    {
        $request = $this->createRequest('payment.finalize.transaction');

        $this->dispatch($request);

        $this->assertNull($request->attributes->get('sw-context-token'));
    }

    public function testItIgnoresSubRequests(): void
    {
        $request = $this->createRequest('payment.finalize.transaction', ['sw-context-token' => self::TOKEN]);

        $this->dispatch($request, HttpKernelInterface::SUB_REQUEST);

        $this->assertNull($request->attributes->get('sw-context-token'));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     */
    private function createRequest(string $route, array $query = [], array $post = []): Request
    {
        $request = new Request($query, $post);
        $request->attributes->set('_route', $route);

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
