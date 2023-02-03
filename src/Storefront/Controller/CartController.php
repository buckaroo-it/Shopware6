<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Session\Session;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CartController extends StorefrontController
{
    protected Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }
     /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/buckaroo/redirect", name="frontend.action.buckaroo.redirect", options={"seo"="false"}, methods={"GET"})
     */
    public function redirectOnBackButtonToEditOrder(): RedirectResponse
    {

        if ($this->session->has('buckaroo_latest_order')) {
            $url = $this->generateUrl(
                'frontend.account.edit-order.page',
                ['orderId' => $this->session->get('buckaroo_latest_order')]
            ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
            $this->session->remove('buckaroo_latest_order');
            return $this->redirect($url);
        }

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }
}
