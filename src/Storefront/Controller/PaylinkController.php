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
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 */
class PaylinkController extends StorefrontController
{
    protected PayLinkService $payLinkService;

    protected OrderService $orderService;

    protected LoggerInterface $logger;

    protected SignatureValidationService $signatureValidationService;

    public function __construct(
        PayLinkService $payLinkService,
        OrderService $orderService,
        LoggerInterface $logger,
        SignatureValidationService $signatureValidationService
    ) {
        $this->payLinkService = $payLinkService;
        $this->orderService = $orderService;
        $this->logger = $logger;
        $this->signatureValidationService = $signatureValidationService;
    }

    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/paylink", defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['order.editor']], name: "api.action.buckaroo.paylink", methods: ["POST"])]
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
                $this->payLinkService->create($request, $order, $context)
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
     * Handle the return from Buckaroo for PayPerEmail. Buckaroo sends the shopper back
     * (POST or GET) with signed brq_ fields after paying via the e-mail link, and a thank
     * you page is shown without a login.
     *
     * The page names the order and links to it through its deep link code, so it is only
     * shown for a return Buckaroo signed for that very order. Without that, anyone who
     * knew an order id could read the deep link code, or show a shop-branded "payment
     * received" page for an unpaid order.
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

        $salesChannelId = $salesChannelContext->getSalesChannelId();

        if (!$this->signatureValidationService->validateReturnSignature($request, $salesChannelId)) {
            $this->logger->warning('PayPerEmail return: No valid Buckaroo signature', ['orderId' => $orderId]);
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

        if ($order->getSalesChannelId() !== $salesChannelId) {
            $this->logger->warning('PayPerEmail return: Order belongs to another sales channel', [
                'orderId' => $orderId,
            ]);
            return $this->redirectToRoute('frontend.home.page');
        }

        $signedData = $this->signatureValidationService->getSignedReturnData($request);

        if (!$this->isReturnForOrder($signedData, $order)) {
            $this->logger->warning('PayPerEmail return: Signed return is for another order', [
                'orderId' => $orderId,
            ]);
            return $this->redirectToRoute('frontend.home.page');
        }

        // Check if payment was cancelled
        $statusCode = $this->getSignedValue($signedData, 'brq_statuscode');
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

    /**
     * The signed return must name the order of the request: by the order id Buckaroo
     * echoes from the additional parameters, or by the order number it was invoiced with.
     * Otherwise a return signed for one order could show the page of any other order.
     *
     * @param array<mixed> $signedData
     */
    private function isReturnForOrder(array $signedData, OrderEntity $order): bool
    {
        foreach (['ADD_orderId', 'brq_AdditionalParameters_orderId'] as $field) {
            $signedOrderId = $this->getSignedValue($signedData, $field);
            if ($signedOrderId !== '' && strcasecmp($signedOrderId, $order->getId()) === 0) {
                return true;
            }
        }

        $orderNumber = (string)$order->getOrderNumber();
        $signedOrderNumber = $this->getSignedValue($signedData, 'brq_invoicenumber');

        return $orderNumber !== '' && $signedOrderNumber === $orderNumber;
    }

    /**
     * Buckaroo does not keep the casing of the field names it echoes back, so the
     * signed data is looked up case-insensitively.
     *
     * @param array<mixed> $signedData
     */
    private function getSignedValue(array $signedData, string $field): string
    {
        foreach ($signedData as $key => $value) {
            if (strcasecmp((string)$key, $field) === 0) {
                return is_string($value) ? trim($value) : '';
            }
        }

        return '';
    }
}
