<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Service\KlarnaMorService;

class KlarnaMorController extends StorefrontController
{
    private KlarnaMorService $klarnaMorService;

    private OrderService $orderService;

    protected LoggerInterface $logger;

    public function __construct(
        KlarnaMorService $klarnaMorService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->klarnaMorService = $klarnaMorService;
        $this->orderService = $orderService;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(
        path: "/api/_action/buckaroo/klarna-mor",
        defaults: ['_routeScope' => ['api'], 'auth_required' => true],
        name: "api.action.buckaroo.klarna_mor",
        methods: ["POST"]
    )]
    public function action(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('orderId');
        $action  = $request->get('action');

        if (empty($orderId) || !is_string($orderId)) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans('buckaroo-payment.missing_order_id')],
                Response::HTTP_NOT_FOUND
            );
        }

        if (empty($action) || !is_string($action)) {
            return new JsonResponse(
                ['status' => false, 'message' => 'Missing or invalid action parameter'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $order = $this->orderService->getOrderById(
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

        if (null === $order) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans('buckaroo-payment.missing_transaction')],
                Response::HTTP_NOT_FOUND
            );
        }

        try {
            return new JsonResponse(
                $this->klarnaMorService->execute($request, $order, $context, $action)
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
