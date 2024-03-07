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
use Buckaroo\Shopware6\Storefront\Controller\WithOrderController;

class RefundController extends WithOrderController
{
    private RefundService $refundService;

    protected LoggerInterface $logger;

    public function __construct(
        RefundService $refundService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->refundService = $refundService;
        $this->logger = $logger;
        parent::__construct($orderService);
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(
        path: "/api/_action/buckaroo/refund",
        defaults: ['_routeScope' => ['api']],
        name: "api.action.buckaroo.refund",
        methods: ["POST"]
    )]
    public function refundBuckaroo(Request $request, Context $context): JsonResponse
    {
        $transactionsToRefund = $request->get('transactionsToRefund');

        try {
            if (
                !is_array($transactionsToRefund) ||
                count($transactionsToRefund) === 0
            ) {
                return new JsonResponse(
                    ['status' => false, 'message' => $this->trans("buckaroo-payment.cannotRefundMissingPayment")],
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
                ['status' => false, 'message' => $this->trans("buckaroo-payment.refund.already_refunded")],
                Response::HTTP_BAD_REQUEST
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
