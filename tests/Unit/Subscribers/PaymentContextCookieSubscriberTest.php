<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentContextCookieSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PaymentContextCookieSubscriberTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

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

    public function testItSetsASecureCookieWhenTokenWasRestoredFromTheUrl(): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN]);
        $request->attributes->set('sw-context-token', self::TOKEN);

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure(), 'The payment context cookie must be marked as HTTPS-only.');
    }

    public function testItKeepsTheCookieSecureOnANonSecureRequest(): void
    {
        $request = Request::create('http://shop.test/checkout/cart?sw-context-token=' . self::TOKEN);
        $request->attributes->set('sw-context-token', self::TOKEN);

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function testItPreservesTheRemainingCookieAttributes(): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN]);
        $request->attributes->set('sw-context-token', self::TOKEN);

        $cookie = $this->dispatch($request);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('sw-context-token', $cookie->getName());
        $this->assertSame(self::TOKEN, $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
    }

    public function testItAlsoHandlesThePrefixedRequestParameters(): void
    {
        $request = new Request([], ['add_sw-context-token' => self::TOKEN]);
        $request->attributes->set('sw-context-token', self::TOKEN);

        $this->assertInstanceOf(Cookie::class, $this->dispatch($request));
    }

    public function testItDoesNothingWhenThereIsNoContextTokenAttribute(): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN]);

        $this->assertNull($this->dispatch($request));
    }

    public function testItDoesNothingWhenTheTokenDidNotComeFromTheUrl(): void
    {
        $request = new Request();
        $request->attributes->set('sw-context-token', self::TOKEN);

        $this->assertNull($this->dispatch($request));
    }

    private function dispatch(Request $request): ?Cookie
    {
        $response = new Response();
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $this->subscriber->onKernelResponse($event);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'sw-context-token') {
                return $cookie;
            }
        }

        return null;
    }
}
