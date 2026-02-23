<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the sw-context-token cookie when we restored it from the URL (payment return).
 * With SameSite=lax, the session cookie may not be sent on cross-site redirect from Buckaroo.
 * By explicitly setting this cookie in the response, the next request (redirect to cart)
 * will have it, allowing context restoration without cookie_samesite: null.
 */
class PaymentContextCookieSubscriber implements EventSubscriberInterface
{
    private const CONTEXT_TOKEN_LIFETIME_DAYS = 1;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -5],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $contextToken = $request->attributes->get('sw-context-token');

        if (!is_string($contextToken) || $contextToken === '') {
            return;
        }

        // Only set cookie when we restored from URL (token was in query, not cookie)
        $tokenFromUrl = $request->query->has('sw-context-token')
            || $request->query->has('add_sw-context-token')
            || $request->request->has('sw-context-token')
            || $request->request->has('add_sw-context-token');
        if (!$tokenFromUrl) {
            return;
        }

        $response = $event->getResponse();
        $expire = new \DateTimeImmutable('+' . self::CONTEXT_TOKEN_LIFETIME_DAYS . ' days');

        $cookie = Cookie::create('sw-context-token')
            ->withValue($contextToken)
            ->withExpires($expire)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX);

        $response->headers->setCookie($cookie);
    }
}
