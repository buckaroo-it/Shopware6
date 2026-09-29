<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the customer's context token in a dedicated cookie while they are at Buckaroo.
 * The storefront session cookie is SameSite=lax, so the browser drops it when Buckaroo sends the
 * customer back with a cross-site POST. This cookie is SameSite=none and is therefore sent on that
 * return, which lets PaymentContextRestoreSubscriber restore the session without the token ever
 * appearing in a URL.
 */
class PaymentContextCookieSubscriber implements EventSubscriberInterface
{
    public const COOKIE_NAME = 'buckaroo-payment-context';

    private const ATTRIBUTE_TOKEN = '_buckaroo_payment_context_token';

    private const LIFETIME = '+2 hours';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -5],
        ];
    }

    /**
     * Marks the current request so its response hands the customer's own context token to the browser.
     * Only the browser that is already using that context may receive it.
     */
    public static function rememberForReturn(?Request $request, SalesChannelContext $salesChannelContext): void
    {
        if ($request === null) {
            return;
        }

        $requestContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $token = $salesChannelContext->getToken();

        if (!$requestContext instanceof SalesChannelContext
            || $token === ''
            || $requestContext->getToken() !== $token
        ) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE_TOKEN, $token);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $contextToken = $request->attributes->get(self::ATTRIBUTE_TOKEN);

        if (is_string($contextToken) && $contextToken !== '') {
            $response->headers->setCookie(
                Cookie::create(self::COOKIE_NAME, secure: true)
                    ->withValue($contextToken)
                    ->withExpires(new \DateTimeImmutable(self::LIFETIME))
                    ->withPath('/')
                    ->withSecure(true)
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_NONE)
            );
            return;
        }

        // The customer is back on the finish page, the token is no longer needed
        if ($request->attributes->get('_route') === 'frontend.checkout.finish.page'
            && $request->cookies->has(self::COOKIE_NAME)
        ) {
            $response->headers->clearCookie(self::COOKIE_NAME, '/', null, true, true, Cookie::SAMESITE_NONE);
        }
    }
}
