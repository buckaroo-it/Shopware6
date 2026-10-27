<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
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

    /**
     * Show the QR code of the shopper's own order. The image URL is the one Buckaroo
     * returned, stored on the order transaction by the payment handler; it is never
     * taken from the request, so nobody can show a QR of their own on a page of the shop.
     */
    #[Route(
        path: "/buckaroo/ideal/qr",
        defaults: ['_routeScope' => ['storefront']],
        name: "frontend.action.buckaroo.ideal.qr",
        options: ["seo" => false],
        methods: ["GET"]
    )]
    public function displayPage(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $orderId = $request->query->get('orderId');
        if (!is_string($orderId) || $orderId === '') {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $order = $this->getOrderOfShopper($orderId, $salesChannelContext, ['orderCustomer', 'transactions']);
        $qrImage = $order !== null ? $this->getQrImageUrl($order) : null;

        if ($order === null || $qrImage === null) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront('@BuckarooPayments/storefront/buckaroo/ideal-qr.html.twig', [
            'qrImage' => $qrImage,
            'orderId' => $order->getId(),
            'cancelUrl' => $this->getCancelUrl($order->getId()),
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
        $orderId = $request->request->get('orderId');
        if (!is_string($orderId) || $orderId === '') {
            return new JsonResponse(["error" => true, "message" => "Invalid order id."]);
        }

        $order = $this->getOrderOfShopper(
            $orderId,
            $salesChannelContext,
            [
                'orderCustomer',
                'transactions',
                'transactions.stateMachineState',
            ]
        );
        if ($order === null) {
            return new JsonResponse(["error" => true, "message" => "Cannot find order"]);
        }

        $transaction = $order->getTransactions()?->last();

        if ($transaction === null) {
            return new JsonResponse(["error" => true, "message" => "Cannot find transaction"]);
        }

        $state = '';

        if ($transaction->getStateMachineState() !== null) {
            $state = $transaction->getStateMachineState()->getTechnicalName();
        }


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

    /**
     * The order of the request, when it was placed by the customer of the current session
     * in the current sales channel. Any order id can be typed in, so without this check
     * the page and the status poll would serve the order of any other shopper.
     *
     * @param array<int, string> $associations
     */
    private function getOrderOfShopper(
        string $orderId,
        SalesChannelContext $salesChannelContext,
        array $associations
    ): ?OrderEntity {
        $customerId = $salesChannelContext->getCustomer()?->getId();
        if ($customerId === null) {
            return null;
        }

        $order = $this->orderService->getOrderById($orderId, $associations, $salesChannelContext->getContext());
        if ($order === null) {
            return null;
        }

        if ($order->getSalesChannelId() !== $salesChannelContext->getSalesChannelId()) {
            return null;
        }

        if ($order->getOrderCustomer()?->getCustomerId() !== $customerId) {
            return null;
        }

        return $order;
    }

    private function getQrImageUrl(OrderEntity $order): ?string
    {
        $customFields = $order->getTransactions()?->last()?->getCustomFields() ?? [];
        $qrImage = $customFields[IdealQrPaymentHandler::QR_IMAGE_URL_FIELD] ?? null;

        return is_string($qrImage) && $qrImage !== '' ? $qrImage : null;
    }
}
