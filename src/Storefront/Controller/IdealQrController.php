<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

class IdealQrController extends StorefrontController
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    #[Route(
        path: "/buckaroo/ideal/qr",
        defaults: ['_routeScope' => ['storefront']],
        name: "frontend.action.buckaroo.ideal.qr",
        options: ["seo" => false],
        methods: ["GET"]
    )]
    public function displayPage(Request $request): Response
    {
        if (!$request->query->has('qrImage') || !is_string($request->get('qrImage'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        if (!$request->query->has('transactionKey') || !is_string($request->get('transactionKey'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        if (!$request->query->has('orderId') || !is_string($request->get('orderId'))) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }
        
        return $this->renderStorefront('@BuckarooPayments/storefront/buckaroo/ideal-qr.html.twig', [
            'qrImage' => urldecode($request->get('qrImage')),
            'orderId' => $request->get('orderId'),
            'cancelUrl' => $this->getCancelUrl($request->get('orderId')),
            'pullUrl' => $this->generateUrl('frontend.action.buckaroo.ideal.qr.status', [], 0)
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

    #[Route(
        path: "/buckaroo/ideal/qr/status",
        defaults: ['_routeScope' => ['storefront'], "XmlHttpRequest" => true],
        name: "frontend.action.buckaroo.ideal.qr.status",
        options: ["seo" => false],
        methods: ["POST"]
    )]
    public function pullStatus(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        if (!$request->request->has('orderId') || !is_string($request->get('orderId'))) {
            return new JsonResponse(["error" => true, "message" => "Invalid order id."]);
        }

        $order = $this->orderService->getOrderById(
            $request->get('orderId'),
            [
                'transactions',
                'transactions.stateMachineState',
            ],
            $salesChannelContext->getContext()
        );
        if ($order === null) {
            return new JsonResponse(["error" => true, "message" => "Cannot find order"]);
        }

        $transaction = $order->getTransactions()->last();

        if ($transaction === null) {
            return new JsonResponse(["error" => true, "message" => "Cannot find transaction"]);
        }

        $state = $transaction->getStateMachineState()->getTechnicalName();

        if (
            in_array(
                $state,
                [
                    OrderTransactionStates::STATE_PAID,
                    OrderTransactionStates::STATE_PARTIALLY_PAID,
                    OrderTransactionStates::STATE_REFUNDED,
                    OrderTransactionStates::STATE_PARTIALLY_REFUNDED
                ]
            )
        ) {
            $returnUrl = $this->generateUrl(
                'frontend.checkout.finish.page',
                ['orderId' => $order->getId()],
                0
            );
            return new JsonResponse(["redirectUrl" => $returnUrl]);
        }

        if (
            in_array(
                $state,
                [
                    OrderTransactionStates::STATE_CANCELLED,
                    OrderTransactionStates::STATE_FAILED
                ]
            )
        ) {
            return new JsonResponse(["redirectUrl" => $this->getCancelUrl($order->getId())]);
        }
        return new JsonResponse(["state" => $state]);
    }
}
