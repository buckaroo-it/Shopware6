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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

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
    #[Route(path: "/api/_action/buckaroo/paylink", defaults: ['_routeScope' => ['api'], 'auth_required' => true], name: "api.action.buckaroo.paylink", methods: ["POST"])]
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

    /**
     * Handle POST return from Buckaroo for PayPerEmail
     * Buckaroo sends POST data when customer pays via email link
     * Shows a thank you page accessible without login
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return Response
     */
    #[Route(
        path: "/buckaroo/payperemail/return",
        defaults: ['_routeScope' => ['storefront']],
        name: "buckaroo.payperemail.return",
        options: ["seo" => false],
        methods: ["GET", "POST"]
    )]
    public function payPerEmailReturn(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->logger->info('PayPerEmail return called', [
            'method' => $request->getMethod(),
            'orderId' => $request->get('orderId'),
            'brq_statuscode' => $request->get('brq_statuscode'),
        ]);

        // Get orderId from request (either query param or POST data)
        $orderId = $request->get('orderId');
        
        if (empty($orderId) || !is_string($orderId)) {
            $this->logger->error('PayPerEmail return: Missing orderId');
            return $this->redirectToRoute('frontend.home.page');
        }

        // Load order with deepLinkCode for guest access
        $order = $this->orderService->getOrderById(
            $orderId,
            ['orderCustomer'],
            $salesChannelContext->getContext()
        );

        if ($order === null) {
            $this->logger->error('PayPerEmail return: Order not found', ['orderId' => $orderId]);
            return $this->redirectToRoute('frontend.home.page');
        }

        // Check if payment was cancelled
        $statusCode = $request->get('brq_statuscode');
        $cancel = $request->query->get('cancel');
        
        if ($cancel === '1' || $statusCode === '890') {
            $this->logger->info('PayPerEmail return: Payment cancelled', ['orderId' => $orderId]);
            
            // Show cancellation message
            return $this->renderStorefront('@BuckarooPayments/storefront/buckaroo/payperemail-cancelled.html.twig', [
                'order' => $order,
                'orderNumber' => $order->getOrderNumber(),
            ]);
        }

        // Show success confirmation page
        // The actual payment status update happens via push notification
        $this->logger->info('PayPerEmail return: Showing confirmation', ['orderId' => $orderId]);
        
        return $this->renderStorefront('@BuckarooPayments/storefront/buckaroo/payperemail-confirmation.html.twig', [
            'order' => $order,
            'orderNumber' => $order->getOrderNumber(),
            'orderDeepLink' => $order->getDeepLinkCode(),
        ]);
    }
}
