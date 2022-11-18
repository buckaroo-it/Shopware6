<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Exception;


/**
 */
class RefundController extends StorefrontController
{
    
    private $checkoutHelper;
    

    public function __construct(
        CheckoutHelper $checkoutHelper
    ) {
        $this->checkoutHelper = $checkoutHelper;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/_action/buckaroo/refund", name="api.action.buckaroo.refund", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function refundBuckaroo(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->get('transaction');
        $transactionsToRefund = $request->get('transactionsToRefund');
        $orderItems = $request->get('orderItems');

        if (empty($orderId)) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_order_id")],
                Response::HTTP_NOT_FOUND
            );
        }

        $order = $this->checkoutHelper->getOrderById($orderId, $context);

        if (null === $order) {
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_transaction")],
                Response::HTTP_NOT_FOUND
            );
        }
        $responses = [];
        try {
            foreach ($transactionsToRefund as $item) {
                $responses[] = $this->checkoutHelper->refundTransaction($order, $context, $item, 'refund', $orderItems, (float) $request->get('customRefundAmount'));
            }
        } catch (Exception $exception) {
            return new JsonResponse(
                [
                    'status'  => false,
                    'message' => $exception->getMessage(),
                    'code'    => 0,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if($responses){
            return new JsonResponse($responses);
        }

        return new JsonResponse(
            [
                'status'  => false,
                'message' => 'Unfortunately an error occurred while processing your refund. Please try again.',
                'code'    => 0,
            ],
            Response::HTTP_BAD_REQUEST
        );

    }
}
