<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService as CoreCartService;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Removes Buckaroo payment methods whose minimum / maximum order amount excludes the
 * current cart total from the available payment methods (`onlyAvailable`), the same
 * way Shopware removes payment methods whose availability rule does not match.
 *
 * Registered outside Shopware's cached route (6.5 / 6.6), so a cart dependent list
 * is never cached.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    /**
     * Requests about an existing order, where Shopware lists or checks the payment
     * methods in a context built from the order rather than the cart. The order total
     * is checked there instead (storefront subscriber, SetPaymentOrderRouteDecorator).
     */
    private const ORDER_ROUTES = [
        'frontend.account.edit-order.page',
        'frontend.account.edit-order.change-payment-method',
        'frontend.account.edit-order.update-order',
        'store-api.order.set-payment',
    ];

    public function __construct(
        private readonly AbstractPaymentMethodRoute $decorated,
        private readonly PaymentAmountLimitService $limitService,
        private readonly CoreCartService $cartService,
        private readonly RequestStack $requestStack
    ) {
    }

    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);

        if (!$this->isAvailabilityRequest($request) || $this->isOrderRequest()) {
            return $response;
        }

        $paymentMethods = $response->getPaymentMethods();
        if (!$this->hasLimitedPaymentMethod($paymentMethods, $context)) {
            return $response;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $allowed = $paymentMethods->filter(
            fn ($paymentMethod) => $this->limitService->isAllowedForCart($paymentMethod, $cart, $context)
        );

        if ($allowed->count() === $paymentMethods->count()) {
            return $response;
        }

        $result = $response->getObject();
        if ($result instanceof EntitySearchResult) {
            $result->assign([
                'entities' => $allowed,
                'elements' => $allowed->getElements(),
                'total' => $allowed->count(),
            ]);
        }

        return $response;
    }

    private function isAvailabilityRequest(Request $request): bool
    {
        return $request->query->getBoolean('onlyAvailable') || $request->request->getBoolean('onlyAvailable');
    }

    private function isOrderRequest(): bool
    {
        $mainRequest = $this->requestStack->getMainRequest();

        return $mainRequest !== null
            && \in_array($mainRequest->attributes->get('_route'), self::ORDER_ROUTES, true);
    }

    /**
     * Loading the cart is only needed when a listed Buckaroo method has a limit.
     */
    private function hasLimitedPaymentMethod(
        PaymentMethodCollection $paymentMethods,
        SalesChannelContext $context
    ): bool {
        $salesChannelId = $context->getSalesChannelId();

        foreach ($paymentMethods as $paymentMethod) {
            $buckarooKey = $this->limitService->getBuckarooKey($paymentMethod);
            if ($buckarooKey !== null && $this->limitService->hasLimits($buckarooKey, $salesChannelId)) {
                return true;
            }
        }

        return false;
    }
}
