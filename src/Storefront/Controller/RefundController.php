<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\RefundService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RefundController extends StorefrontController
{
    protected LoggerInterface $logger;

    private RefundService $refundService;

    private OrderService $orderService;

    public function __construct(
        RefundService $refundService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->refundService = $refundService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/buckaroo/refund', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['order_refund.editor']], name: 'api.action.buckaroo.refund', methods: ['POST'])]
    public function refundBuckaroo(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('transaction');
        $transactionsToRefund = $request->get('transactionsToRefund');

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
            if (
                !is_array($transactionsToRefund)
                || count($transactionsToRefund) === 0
            ) {
                return new JsonResponse(
                    ['status' => false, 'message' => $this->trans('buckaroo.cannotRefundMissingPayment')],
                    Response::HTTP_NOT_FOUND
                );
            }

            $responses = $this->refundService->refundAll(
                $request,
                $order,
                $context,
                $transactionsToRefund,
            );

            if (count($responses)) {
                return new JsonResponse($responses);
            }

            return new JsonResponse(
                ['status' => false, 'message' => $this->trans('buckaroo-payment.refund.already_refunded')],
                Response::HTTP_BAD_REQUEST
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
