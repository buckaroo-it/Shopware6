<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Subscribers\PaymentReturnContextSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PaymentReturnContextSubscriberTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const FINISH_URL = 'https://shop.test/checkout/finish?orderId=abc';

    public function testItAppendsTheRestoredTokenToTheFinishRedirect(): void
    {
        $request = new Request(['sw-context-token' => self::TOKEN]);
        $request->attributes->set('sw-context-token', self::TOKEN);

        $location = $this->dispatch($request, new RedirectResponse(self::FINISH_URL));

        $this->assertSame(self::FINISH_URL . '&sw-context-token=' . self::TOKEN, $location);
    }

    public function testItDoesNotForwardATokenThatWasNotRestored(): void
    {
        // Token in the URL, but PaymentContextRestoreSubscriber rejected it (no attribute set)
        $request = new Request(['sw-context-token' => self::TOKEN]);

        $location = $this->dispatch($request, new RedirectResponse(self::FINISH_URL));

        $this->assertSame(self::FINISH_URL, $location);
    }

    public function testItIgnoresRedirectsToOtherPages(): void
    {
        $request = new Request();
        $request->attributes->set('sw-context-token', self::TOKEN);

        $location = $this->dispatch($request, new RedirectResponse('https://shop.test/checkout/cart'));

        $this->assertSame('https://shop.test/checkout/cart', $location);
    }

    private function dispatch(Request $request, Response $response): ?string
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        (new PaymentReturnContextSubscriber())->onKernelResponse($event);

        return $response->headers->get('Location');
    }
}
