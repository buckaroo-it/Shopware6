<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CaptureService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 */
class CaptureController extends StorefrontController
{
    private CaptureService $captureService;

    private OrderService $orderService;

    public function __construct(
        CaptureService $captureService,
        OrderService $orderService
    ) {
        $this->captureService = $captureService;
        $this->orderService = $orderService;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/_action/buckaroo/capture", name="api.action.buckaroo.capture", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function captureBuckaroo(Request $request, Context $context): JsonResponse
    {

        $orderId = $request->get('transaction');

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
            return new JsonResponse(
                $this->captureService->captureTransaction($request, $order, $context)
            );
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
                'message' => $this->trans("buckaroo-payment.capture.general_capture_error"),
                'code'    => 0,
            ],
            Response::HTTP_BAD_REQUEST
        );
    }
}
