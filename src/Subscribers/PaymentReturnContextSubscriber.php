<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures the context token is passed when redirecting to checkout/finish after payment return.
 * When returning from Buckaroo, session cookies may not be sent (cross-site). Appending the
 * token to the redirect URL allows the finish page to restore the user's context/login state.
 */
class PaymentReturnContextSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$response->isRedirection()) {
            return;
        }

        $redirectUrl = $response->headers->get('Location');
        if ($redirectUrl === null || $redirectUrl === '') {
            return;
        }

        if (strpos($redirectUrl, 'checkout/finish') === false) {
            return;
        }

        $contextToken = $request->query->get('add_sw-context-token')
            ?? $request->request->get('add_sw-context-token')
            ?? $request->query->get('sw-context-token')
            ?? $request->request->get('sw-context-token');
        if (!is_string($contextToken) || $contextToken === '') {
            return;
        }

        if (str_contains($redirectUrl, 'sw-context-token=')) {
            return;
        }

        $separator = str_contains($redirectUrl, '?') ? '&' : '?';
        $response->headers->set(
            'Location',
            $redirectUrl . $separator . 'sw-context-token=' . rawurlencode($contextToken)
        );
    }
}
