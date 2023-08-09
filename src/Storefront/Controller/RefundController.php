<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\RefundService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;

class RefundController extends StorefrontController
{
    private RefundService $refundService;

    private OrderService $orderService;

    protected LoggerInterface $logger;
    public function __construct(
        RefundService $refundService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->refundService = $refundService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/refund", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.refund", methods: ["POST"])]
    public function refundBuckaroo(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('transaction');
        $transactionsToRefund = $request->get('transactionsToRefund');

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
            $responses = [];

            if (is_array($transactionsToRefund)) {
                foreach ($transactionsToRefund as $item) {
                    if (is_array($item)) {
                        $responses[] = $this->refundService->refund(
                            $request,
                            $order,
                            $context,
                            $item,
                        );
                    }
                }
            }
            return new JsonResponse($responses);
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
