<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\OrderService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CaptureController extends StorefrontController
{
    protected LoggerInterface $logger;

    private CaptureService $captureService;

    private OrderService $orderService;

    public function __construct(
        CaptureService $captureService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->captureService = $captureService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/buckaroo/capture', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['order.editor']], name: 'api.action.buckaroo.capture', methods: ['POST'])]
    public function captureBuckaroo(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('transaction');

        if (empty($orderId) || !is_string($orderId)) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans('buckaroo-payment.missing_order_id')],
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
                    'salesChannel',
                    'currency',
                ],
                $context
            );

        if ($order === null) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans('buckaroo-payment.missing_transaction')],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            return new JsonResponse(
                $this->captureService->capture($request, $order, $context)
            );
        } catch (\Throwable $exception) {
            $this->logger->debug((string) $exception);

            return new JsonResponse(
                [
                    'status' => false,
                    'message' => $this->trans('buckaroo-payment.general_request_error'),
                    'code' => 0,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }
    }
}
