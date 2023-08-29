<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\PayByBankService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CartController extends StorefrontController
{
    protected PayByBankService $payByBankService;

    public function __construct(PayByBankService $payByBankService)
    {
        $this->payByBankService = $payByBankService;
    }

    #[Route(path: "/buckaroo/redirect", defaults: ['_routeScope' => ['storefront']], name: "frontend.action.buckaroo.redirect", options: ["seo" => false], methods: ["GET"])]
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

    #[Route(
        path: "/buckaroo/pybybank",
        defaults: ['_routeScope' => ['storefront'], "XmlHttpRequest" => true],
        name: "frontend.action.buckaroo.paybybank",
        options: ["seo" => false],
        methods: ["POST"]
    )]
    public function payByBankSelector(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->renderStorefront(
            '@Storefront/storefront/buckaroo/paybybank.html.twig',
            [
                "payByBankIssuers" => $this->payByBankService->getIssuers(
                    $salesChannelContext->getCustomer()
                ),
                "isMobile" => $request->get('isMobile', false)
            ]
        );
    }


    #[Route(
        path: "/buckaroo/pybybank/logo",
        defaults: ['_routeScope' => ['storefront'], "XmlHttpRequest" => true],
        name: "frontend.action.buckaroo.paybybank.logo",
        options: ["seo" => false],
        methods: ["POST"]
    )]
    public function payByBankIssuerLogo(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        if (!$request->request->has('issuer') || !is_string($request->request->get('issuer'))) {
            return new JsonResponse([
                "error" => true,
                "message" => "No valid issuer provided"
            ]);
        }

        return new JsonResponse([
            "error" => false,
            "logo" => $this->payByBankService->getIssuerLogo(
                $request->request->get('issuer')
            )
        ]);
    }
}
