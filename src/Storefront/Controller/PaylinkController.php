<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\PayLinkService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;

/**
 */
class PaylinkController extends StorefrontController
{
    protected PayLinkService $payLinkService;

    protected OrderService $orderService;

    protected LoggerInterface $logger;

    public function __construct(
        PayLinkService $payLinkService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->payLinkService = $payLinkService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/paylink", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.paylink", methods:["POST"])]
    public function paylinkBuckaroo(Request $request, Context $context): JsonResponse
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
                $this->payLinkService->create($request, $order)
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
