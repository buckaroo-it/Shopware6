<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CaptureService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;

/**
 */
class CaptureController extends StorefrontController
{
    private CaptureService $captureService;

    private OrderService $orderService;

    protected LoggerInterface $logger;

    public function __construct(
        CaptureService $captureService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->captureService = $captureService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/capture", defaults: ['_routeScope' => ['api'], 'auth_required' => true], name: "api.action.buckaroo.capture", methods: ["POST"])]
    public function captureBuckaroo(Request $request, Context $context): JsonResponse
    {

        $orderId = $request->get('transaction');

        if (empty($orderId) || !is_string($orderId)) {
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
                    'salesChannel',
                    'currency'
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
                $this->captureService->capture($request, $order, $context)
            );
        } catch (\Exception $exception) {
            $this->logger->debug((string)$exception);
            return new JsonResponse(
                [
                    'status'  => false,
                    'message' => $exception->getMessage(),
                    'code'    => 0,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }
    }
}
