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
use Buckaroo\Shopware6\Storefront\Controller\WithOrderController;

/**
 */
class PaylinkController extends WithOrderController
{
    protected PayLinkService $payLinkService;

    protected LoggerInterface $logger;

    public function __construct(
        PayLinkService $payLinkService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->payLinkService = $payLinkService;
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
        path: "/api/_action/buckaroo/paylink",
        defaults: ['_routeScope' => ['api']],
        name: "api.action.buckaroo.paylink",
        methods: ["POST"]
    )]
    public function paylinkBuckaroo(Request $request, Context $context): JsonResponse
    {
        try {
            return new JsonResponse(
                $this->payLinkService->create($request, $this->getOrder($request, $context))
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
