<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores the sales channel context token from the payment context cookie when returning from Buckaroo.
 * Setting the token in the session early allows the rest of the request to use the correct context.
 */
class PaymentContextRestoreSubscriber implements EventSubscriberInterface
{
    /**
     * Routes Buckaroo redirects the customer back to.
     */
    private const RESTORE_ROUTES = [
        'payment.finalize.transaction',
        'frontend.checkout.finish.page',
        'frontend.action.buckaroo.cancel',
    ];

    public function __construct(private readonly ?Connection $connection = null)
    {
    }

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
            $session = $request->getSession();
            $session->set('sw-context-token', $contextToken);

            // With core.systemWideLoginRegistration.isCustomerBoundToSalesChannel enabled,
            // StorefrontSubscriber::startSession() renews the token of a session whose sales channel
            // differs from the request's. The session started on the cross-site return carries no
            // sales channel yet, so without this the next storefront request (/checkout/finish)
            // would swap the restored token for an anonymous one and log the customer out.
            $salesChannelId = $this->getSalesChannelId($request, $contextToken);
            if ($salesChannelId !== null) {
                $session->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, $salesChannelId);
            }
        }
    }

    /**
     * The sales channel the restored context belongs to. /payment/finalize-transaction is not a
     * storefront request, so the request carries none there; the persisted context does.
     */
    private function getSalesChannelId(Request $request, string $contextToken): ?string
    {
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (is_string($salesChannelId) && Uuid::isValid($salesChannelId)) {
            return $salesChannelId;
        }

        if ($this->connection === null) {
            return null;
        }

        try {
            $salesChannelId = $this->connection->fetchOne(
                'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_api_context WHERE token = :token',
                ['token' => $contextToken]
            );
        } catch (\Throwable) {
            return null;
        }

        return is_string($salesChannelId) && Uuid::isValid($salesChannelId) ? $salesChannelId : null;
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
