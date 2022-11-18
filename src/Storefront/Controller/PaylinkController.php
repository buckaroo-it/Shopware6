<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Exception;
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

use Psr\Log\LoggerInterface;

/**
 */
class PaylinkController extends StorefrontController
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    private $checkoutHelper;
    
    public function __construct(
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger
    ) {
        $this->checkoutHelper = $checkoutHelper;
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
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_order_id")],
                Response::HTTP_NOT_FOUND
            );
        }

        $order = $this->checkoutHelper->getOrderById($orderId, $context);

        if (null === $order) {
            $this->logger->info(__METHOD__ . "|10|");
            return new JsonResponse(
                ['status' => false, 'message' => $this->trans("buckaroo-payment.missing_transaction")],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->logger->info(__METHOD__ . "|15|", [$orderId]);

        try {
            $this->logger->info(__METHOD__ . "|20|");
            $response = $this->checkoutHelper->createPaylink($order, $context);
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
                'message' => $this->trans("buckaroo-payment.general_request_error"),
                'code'    => 0,
            ],
            Response::HTTP_BAD_REQUEST
        );

    }
}
