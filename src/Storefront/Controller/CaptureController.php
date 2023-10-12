<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CaptureService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Buckaroo\Shopware6\Storefront\Controller\WithOrderController;

/**
 */
class CaptureController extends WithOrderController
{
    private CaptureService $captureService;

    protected LoggerInterface $logger;

    public function __construct(
        CaptureService $captureService,
        OrderService $orderService,
        LoggerInterface $logger
    ) {
        $this->captureService = $captureService;
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
        path: "/api/_action/buckaroo/capture",
        defaults: ['_routeScope' => ['api']],
        name: "api.action.buckaroo.capture",
        methods: ["POST"]
    )]
    public function captureBuckaroo(Request $request, Context $context): JsonResponse
    {
        try {
            return new JsonResponse(
                $this->captureService->capture(
                    $request,
                    $this->getOrder($request, $context),
                    $context
                )
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
