<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;

class IdealQrController extends StorefrontController
{
    #[Route(
        path: "/buckaroo/ideal/qr",
        defaults: ['_routeScope' => ['storefront']],
        name: "frontend.action.buckaroo.ideal.qr",
        options: ["seo"=> false], methods:["GET"]
    )]
    public function displayPage(Request $request): Response
    {
        if(!$request->query->has('qrImage') || !is_string($request->get('qrImage'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        if(!$request->query->has('transactionKey') || !is_string($request->get('transactionKey'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        if(!$request->query->has('orderId') || !is_string($request->get('orderId'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront('@BuckarooPayments/storefront/buckaroo/ideal-qr.html.twig', [
            'qr_image' => urldecode($request->get('qrImage')),
            'transaction_key' =>$request->get('transactionKey'),
            'cancel_url' => $this->getCancelUrl($request->get('orderId'))
        ]);

    }

    /**
     * Get cancel url
     *
     * @param string $orderId
     *
     * @return string
     */
    private function getCancelUrl(string $orderId): string
    {
            return $this->generateUrl(
                'frontend.account.edit-order.page',
                ['orderId' => $orderId],
                0
            ) . "?error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT";
    }
}