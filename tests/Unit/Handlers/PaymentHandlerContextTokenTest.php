<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Handlers\PaymentHandlerModern;
use Buckaroo\Shopware6\Handlers\PaymentHandlerSimple;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * pay() also runs where no customer session exists (store-api / headless checkouts and
 * PSP callbacks), so the sales channel context token has to be recovered from the request
 * alone. The token matters for the redirect flow: it is appended to the Buckaroo returnURL
 * and sent as an additional parameter, which is how the finish page still recognises a
 * guest order after the customer comes back from the PSP.
 *
 * These tests pin that the handlers resolve the token without ever reading the session.
 * They only run on the Shopware line that ships AbstractPaymentHandler (6.7+), which is
 * the line on which both handlers expose this resolution.
 */
class PaymentHandlerContextTokenTest extends TestCase
{
    /**
     * @var string
     */
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    protected function setUp(): void
    {
        if (!class_exists(AbstractPaymentHandler::class)) {
            $this->markTestSkipped('The modern payment handlers require Shopware >= 6.7');
        }
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function handlerProvider(): array
    {
        return [
            'modern' => [PaymentHandlerModern::class],
            'simple' => [PaymentHandlerSimple::class],
        ];
    }

    /**
     * @dataProvider handlerProvider
     */
    public function testTokenIsReadFromTheHeader(string $handlerClass): void
    {
        $request = new Request();
        $request->headers->set('sw-context-token', self::TOKEN);

        $this->assertSame(self::TOKEN, $this->resolveToken($handlerClass, $request));
    }

    /**
     * @dataProvider handlerProvider
     */
    public function testTokenIsReadFromRequestParametersWhenTheHeaderIsMissing(string $handlerClass): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN]);

        $this->assertSame(self::TOKEN, $this->resolveToken($handlerClass, $request));
    }

    /**
     * The storefront does not put the token in the URL: it is carried by the
     * SalesChannelContext that the routing layer resolved for this request. That object
     * replaces the former session lookup and is present for store-api requests too.
     *
     * @dataProvider handlerProvider
     */
    public function testTokenFallsBackToTheResolvedSalesChannelContext(string $handlerClass): void
    {
        $request = new Request();
        $request->attributes->set(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT,
            $this->salesChannelContextWithToken(self::TOKEN)
        );

        $this->assertSame(self::TOKEN, $this->resolveToken($handlerClass, $request));
    }

    /**
     * @dataProvider handlerProvider
     */
    public function testTheHeaderWinsOverTheResolvedSalesChannelContext(string $handlerClass): void
    {
        $request = new Request();
        $request->headers->set('sw-context-token', self::TOKEN);
        $request->attributes->set(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT,
            $this->salesChannelContextWithToken('other-token')
        );

        $this->assertSame(self::TOKEN, $this->resolveToken($handlerClass, $request));
    }

    /**
     * A request without a session must resolve to an empty token instead of throwing.
     * Request::getSession() raises SessionNotFoundException here, so this fails as soon
     * as the session is consulted again.
     *
     * @dataProvider handlerProvider
     */
    public function testASessionlessRequestResolvesToAnEmptyToken(string $handlerClass): void
    {
        $request = new Request();

        $this->assertFalse($request->hasSession(), 'the request must not carry a session');
        $this->assertSame('', $this->resolveToken($handlerClass, $request));
    }

    /**
     * The session is deliberately ignored, even when it holds a token: relying on it is
     * what broke session-less payment contexts.
     *
     * @dataProvider handlerProvider
     */
    public function testATokenStoredInTheSessionIsIgnored(string $handlerClass): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('sw-context-token', self::TOKEN);

        $request = new Request();
        $request->setSession($session);

        $this->assertSame('', $this->resolveToken($handlerClass, $request));
    }

    private function salesChannelContextWithToken(string $token): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getToken')->willReturn($token);

        return $salesChannelContext;
    }

    /**
     * The method is protected on PaymentHandlerModern and private on PaymentHandlerSimple,
     * and neither needs its constructor dependencies to resolve the token.
     *
     * @param class-string $handlerClass
     */
    private function resolveToken(string $handlerClass, Request $request): string
    {
        $handler = (new \ReflectionClass($handlerClass))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod($handlerClass, 'getContextTokenFromRequest');
        $method->setAccessible(true);

        /** @var string $token */
        $token = $method->invoke($handler, $request);

        return $token;
    }
}
