<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class CartController extends StorefrontController
{
    #[Route(path: "/buckaroo/redirect", defaults: ['_routeScope' => ['storefront']], name: "frontend.action.buckaroo.redirect", options: ["seo" => false], methods: ["GET"])]
    public function redirectOnBackButtonToEditOrder(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        if ($session->has('buckaroo_latest_order')) {
            $session->remove('buckaroo_latest_order');
            $url = $this->generateUrl(
                'frontend.checkout.cart.page',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
            return $this->redirect($url);
        }

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }

    #[Route(path: "/buckaroo/cancel", defaults: ['_routeScope' => ['storefront']], name: "frontend.action.buckaroo.cancel", options: ["seo" => false], methods: ["GET", "POST"])]
    public function redirectOnCancel(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $orderId = $session->get('buckaroo_latest_order')
            ?? $request->request->get('ADD_orderId')
            ?? $request->query->get('ADD_orderId')
            ?? $request->request->get('brq_AdditionalParameters_orderId')
            ?? $request->query->get('brq_AdditionalParameters_orderId');

        if ($session->has('buckaroo_latest_order')) {
            $session->remove('buckaroo_latest_order');
        }

        if ($orderId !== null && $orderId !== '') {
            $url = $this->generateUrl(
                'frontend.account.edit-order.page',
                ['orderId' => $orderId],
                UrlGeneratorInterface::ABSOLUTE_URL
            ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
            return $this->redirect($url);
        }

        $url = $this->generateUrl(
            'frontend.checkout.cart.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
        return $this->redirect($url);
    }
}
