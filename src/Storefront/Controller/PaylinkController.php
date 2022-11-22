<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Exception;
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

use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Psr\Log\LoggerInterface;

/**
 */
class PaylinkController extends StorefrontController
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    private $transactionRepository;
    
    private $checkoutHelper;
    
    private $orderRepository;

    public function __construct(
        EntityRepositoryInterface $transactionRepository,
        CheckoutHelper $checkoutHelper,
        EntityRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->checkoutHelper = $checkoutHelper;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/_action/buckaroo/paylink", name="api.action.buckaroo.paylink", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function paylinkBuckaroo(Request $request, Context $context): JsonResponse
    {
        $this->logger->info(__METHOD__ . "|1|");

        $orderId = $request->get('transaction');

        if (empty($orderId)) {
            $this->logger->info(__METHOD__ . "|5|");
            return new JsonResponse(['status' => false, 'message' => 'Missing order orderId'], Response::HTTP_NOT_FOUND);
        }

        $order = $this->checkoutHelper->getOrderById($orderId, $context);

        if (null === $order) {
            $this->logger->info(__METHOD__ . "|10|");
            return new JsonResponse(['status' => false, 'message' => 'Order transaction not found'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info(__METHOD__ . "|15|", [$orderId]);

        try {
            $this->logger->info(__METHOD__ . "|20|");
            $response = $this->checkoutHelper->createPaylink($request, $order, $context);
        } catch (Exception $exception) {
            $this->logger->info(__METHOD__ . "|25|");
            return new JsonResponse(
                [
                    'status'  => false,
                    'message' => $exception->getMessage(),
                    'code'    => 0,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if($response){
            return new JsonResponse($response);
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
