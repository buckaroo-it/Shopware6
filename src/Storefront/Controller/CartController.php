<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class CartController extends StorefrontController
{
     /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/buckaroo/redirect", name="frontend.action.buckaroo.redirect", options={"seo"="false"}, methods={"GET"})
     */
    public function redirectOnBackButtonToEditOrder(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        if ($session->has('buckaroo_latest_order')) {
            $url = $this->generateUrl(
                'frontend.account.edit-order.page',
                ['orderId' => $session->get('buckaroo_latest_order')]
            ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
            $session->remove('buckaroo_latest_order');
            return $this->redirect($url);
        }

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }
}
