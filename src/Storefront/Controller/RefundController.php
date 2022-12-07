<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\RefundService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;


/**
 */
class RefundController extends StorefrontController
{
    private RefundService $refundService;

    private OrderService $orderService;

    public function __construct(
        RefundService $refundService,
        OrderService $orderService
    ) {
        $this->refundService = $refundService;
        $this->orderService = $orderService;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/_action/buckaroo/refund", name="api.action.buckaroo.refund", methods={"POST"})
     * @param Request $request
     * @param Context $context
     *
     * @return RedirectResponse
     */
    public function refundBuckaroo(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('transaction');
        $transactionsToRefund = $request->get('transactionsToRefund');
        $orderItems = $request->get('orderItems');

        if (empty($orderId)) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_order_id")],
                Response::HTTP_NOT_FOUND
            );
        }

        $order = $this->orderService
            ->getOrderById(
                $orderId,
                [
                    'orderCustomer.salutation',
                    'stateMachineState',
                    'lineItems',
                    'transactions',
                    'transactions.paymentMethod',
                    'transactions.paymentMethod.plugin',
                    'salesChannel'
                ],
                $context
            );

        if (null === $order) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_transaction")],
                Response::HTTP_NOT_FOUND
            );
        }
        try {
            $responses = [];
            foreach ($transactionsToRefund as $item) {
                $responses[] = $this->refundService->refundTransaction(
                    $request,
                    $order,
                    $context,
                    $item,
                    $orderItems,
                    (float) $request->get('customRefundAmount')
                );
            }
            return new JsonResponse($responses);
        } catch (\Exception $exception) {
            return new JsonResponse(
                [
                    'status'  => false,
                    'message' => $exception->getMessage(),
                    'code'    => 0,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(
            [
                'status'  => false,
                'message' =>  $this->trans("buckaroo-payment.general_request_error"),
                'code'    => 0,
            ],
            Response::HTTP_BAD_REQUEST
        );
    }
}
