<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores the sales channel context token when returning from Buckaroo.
 * The session cookie may not be sent on the cross-site return, but the payment context cookie
 * (see PaymentContextCookieSubscriber) is. Setting the token in the session early allows the
 * rest of the request to use the correct context. The token is never taken from the URL.
 */
class PaymentContextRestoreSubscriber implements EventSubscriberInterface
{
    /**
     * Routes Buckaroo redirects the customer back to. Only these may restore the context token.
     */
    private const RESTORE_ROUTES = [
        'payment.finalize.transaction',
        'frontend.checkout.finish.page',
        'frontend.action.buckaroo.cancel',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $contextToken = $request->cookies->get(PaymentContextCookieSubscriber::COOKIE_NAME);

        if (!is_string($contextToken) || $contextToken === '') {
            return;
        }

        if (!in_array($request->attributes->get('_route'), self::RESTORE_ROUTES, true)) {
            return;
        }

        // The token is only needed when the browser lost its session on the cross-site return.
        // When the visitor still has a live session with its own context, never replace it
        // (e.g. the customer logged in on another tab while paying, which renews the token).
        if ($this->hasOtherLiveContext($request, $contextToken)) {
            return;
        }

        // Store in request attributes so PaymentServiceDecorator and others can use it
        $request->attributes->set('sw-context-token', $contextToken);

        // Overwrite the request header so SalesChannelRequestContextResolver (KernelEvents::CONTROLLER, prio -10)
        // resolves the correct customer context. StorefrontSubscriber::startSession() (prio 40) runs before
        // this subscriber (prio 5) and, when the PHP session cookie is missing (cross-site / mobile return),
        // writes a fresh anonymous token to the header. Without this override the context resolver would pick
        // up that anonymous token and /checkout/finish would redirect to the register page.
        $request->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $contextToken);

        // Storefront returns carry a session; store-api / headless returns do not, and
        // Request::getSession() throws in that case. The attribute and header set above
        // are what the context resolution actually relies on, so skipping is safe.
        if ($request->hasSession()) {
            $request->getSession()->set('sw-context-token', $contextToken);
        }
    }

    private function hasOtherLiveContext(Request $request, string $contextToken): bool
    {
        if (!$request->hasPreviousSession()) {
            return false;
        }

        $sessionToken = $request->getSession()->get(PlatformRequest::HEADER_CONTEXT_TOKEN);

        return is_string($sessionToken) && $sessionToken !== '' && !hash_equals($sessionToken, $contextToken);
    }
}
