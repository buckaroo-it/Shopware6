<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores the sales channel context token from the request when returning from Buckaroo.
 * When the user returns from the payment gateway, sw-context-token may be in the URL but
 * session cookies might not have been sent (cross-site redirect). Setting the token in the
 * session early allows the rest of the request to use the correct context.
 */
class PaymentContextRestoreSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 50],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $contextToken = $request->query->get('add_sw-context-token')
            ?? $request->request->get('add_sw-context-token')
            ?? $request->query->get('sw-context-token')
            ?? $request->request->get('sw-context-token');

        if (!is_string($contextToken) || $contextToken === '') {
            return;
        }

        // Only restore for payment return, checkout finish, and cancel routes
        $path = (string) $request->getPathInfo();
        $isPaymentReturn = str_contains($path, '/payment/') || str_contains($path, 'payment');
        $isCheckoutFinish = str_contains($path, 'checkout/finish');
        $isBuckarooCancel = str_contains($path, 'buckaroo/cancel');

        if (!$isPaymentReturn && !$isCheckoutFinish && !$isBuckarooCancel) {
            return;
        }

        // Store in request attributes so PaymentServiceDecorator and others can use it
        $request->attributes->set('sw-context-token', $contextToken);

        $session = $request->getSession();
        if ($session !== null) {
            $session->set('sw-context-token', $contextToken);
        }
    }
}
