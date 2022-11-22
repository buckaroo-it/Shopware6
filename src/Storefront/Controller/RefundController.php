<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Exception;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;

/**
 */
class RefundController extends StorefrontController
{
    /** @var LoggerInterface */
    private $logger;
    
    private $transactionRepository;
    
    private $checkoutHelper;
    
    private $orderRepository;

    public function __construct(
        EntityRepositoryInterface $transactionRepository,
        CheckoutHelper $checkoutHelper,
        EntityRepositoryInterface $orderRepository
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->checkoutHelper = $checkoutHelper;
        $this->orderRepository = $orderRepository;
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
            return new JsonResponse(['status' => false, 'message' => 'Missing order orderId'], Response::HTTP_NOT_FOUND);
        }

        $order = $this->checkoutHelper->getOrderById($orderId, $context);

        if (null === $order) {
            return new JsonResponse(['status' => false, 'message' => 'Order transaction not found'], Response::HTTP_NOT_FOUND);
        }
        $responses = [];
        try {
            foreach ($transactionsToRefund as $item) {
                $responses[] = $this->checkoutHelper->refundTransaction($request, $order, $context, $item, 'refund', $orderItems, (float) $request->get('customRefundAmount'));
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
