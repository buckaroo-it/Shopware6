<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentContextCookieSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PaymentContextCookieSubscriberTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const OTHER_TOKEN = 'ffffffffffffffffffffffffffffffff';

    private PaymentContextCookieSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new PaymentContextCookieSubscriber();
    }

    public function testItSubscribesToKernelResponse(): void
    {
        $this->assertSame(
            [KernelEvents::RESPONSE => ['onKernelResponse', -5]],
            PaymentContextCookieSubscriber::getSubscribedEvents()
        );
    }

    public function testItSetsTheCookieWhenThePaymentStartRememberedTheToken(): void
    {
        $request = $this->createRequestWithContext(self::TOKEN);
        PaymentContextCookieSubscriber::rememberForReturn($request, $this->createContext(self::TOKEN));

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame(self::TOKEN, $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure(), 'SameSite=none cookies must be secure.');
        $this->assertTrue($cookie->isHttpOnly(), 'The context token must not be readable by page scripts.');
        $this->assertSame(Cookie::SAMESITE_NONE, $cookie->getSameSite());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->assertLessThanOrEqual(time() + 2 * 3600, $cookie->getExpiresTime());
    }

    public function testItKeepsTheCookieSecureOnANonSecureRequest(): void
    {
        $context = $this->createContext(self::TOKEN);
        $request = Request::create('http://shop.test/checkout/order', 'POST');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $context);
        PaymentContextCookieSubscriber::rememberForReturn($request, $context);

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function testItNeverHandsOutATokenOfAnotherContext(): void
    {
        $request = $this->createRequestWithContext(self::OTHER_TOKEN);
        PaymentContextCookieSubscriber::rememberForReturn($request, $this->createContext(self::TOKEN));

        $this->assertNull($this->dispatch($request));
    }

    public function testItDoesNothingWithoutAResolvedRequestContext(): void
    {
        $request = new Request();
        PaymentContextCookieSubscriber::rememberForReturn($request, $this->createContext(self::TOKEN));

        $this->assertNull($this->dispatch($request));
    }

    public function testItAcceptsAMissingRequest(): void
    {
        PaymentContextCookieSubscriber::rememberForReturn(null, $this->createContext(self::TOKEN));

        $this->addToAssertionCount(1);
    }

    public function testItIgnoresTokensInTheUrl(): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN], ['add_sw-context-token' => self::TOKEN]);

        $this->assertNull($this->dispatch($request));
    }

    public function testItClearsTheCookieOnTheFinishPage(): void
    {
        $request = new Request([], [], ['_route' => 'frontend.checkout.finish.page']);
        $request->cookies->set(PaymentContextCookieSubscriber::COOKIE_NAME, self::TOKEN);

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isCleared());
    }

    public function testItLeavesTheCookieOnOtherPages(): void
    {
        $request = new Request([], [], ['_route' => 'payment.finalize.transaction']);
        $request->cookies->set(PaymentContextCookieSubscriber::COOKIE_NAME, self::TOKEN);

        $this->assertNull($this->dispatch($request));
    }

    public function testItIgnoresSubRequests(): void
    {
        $request = $this->createRequestWithContext(self::TOKEN);
        PaymentContextCookieSubscriber::rememberForReturn($request, $this->createContext(self::TOKEN));

        $this->assertNull($this->dispatch($request, HttpKernelInterface::SUB_REQUEST));
    }

    private function createRequestWithContext(string $token): Request
    {
        $context = $this->createContext($token);
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $context);

        return $request;
    }

    private function createContext(string $token): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn($token);

        return $context;
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ?Cookie
    {
        $response = new Response();
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            $type,
            $response
        );

        $this->subscriber->onKernelResponse($event);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === PaymentContextCookieSubscriber::COOKIE_NAME) {
                return $cookie;
            }
        }

        return null;
    }
}
