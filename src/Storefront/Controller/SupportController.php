<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\BuckarooTransactionService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Buckaroo\Shopware6\Service\TestCredentialsService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class SupportController extends StorefrontController
{
    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $taxRepository;

    protected TestCredentialsService $testCredentialsService;

    protected BuckarooTransactionService $buckarooTransactionService;

    public function __construct(
        TestCredentialsService $testCredentialsService,
        BuckarooTransactionService $buckarooTransactionService,
        EntityRepository $taxRepository
    ) {
        $this->testCredentialsService = $testCredentialsService;
        $this->buckarooTransactionService = $buckarooTransactionService;
        $this->taxRepository = $taxRepository;
    }

    /**
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/version", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.version", methods:["POST", "GET"])]
    public function versionSupportBuckaroo(): JsonResponse
    {
        $phpVersion = $this->getPhpVersionArray();
        return new JsonResponse([
            'phpversion'          => implode('.', $phpVersion),
            'isPhpVersionSupport' => ($phpVersion[0] . $phpVersion[1] >= '71') ? true : false,
        ]);
    }


    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/taxes", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.tax", methods:["POST"])]
    public function getTaxes(Request $request, Context $context): JsonResponse
    {
        $taxes = $this->taxRepository->search(
            new Criteria(),
            $context
        )->getEntities();
        return new JsonResponse([
            'error' => false,
            'taxes' => $taxes
        ]);
    }


    /**
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/getBuckarooTransaction", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.support.version", methods:["POST"])]
    public function getBuckarooTransaction(Request $request, Context $context)
    {
        $orderId = $request->get('transaction');
        if (!is_string($orderId)) {
            throw new \InvalidArgumentException('Order id must be a string');
        }

        return new JsonResponse(
            $this->buckarooTransactionService
                ->getBuckarooTransactionsByOrderId(
                    $orderId,
                    $context
                )
        );
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[Route(path: "/api/_action/buckaroo/getBuckarooApiTest", defaults: ['_routeScope' => ['api']], name: "api.action.buckaroo.support.apitest", methods:["POST"])]
    public function getBuckarooApiTest(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->testCredentialsService->execute($request)
        );
    }

    /**
     * @return array<mixed>
     */
    private function getPhpVersionArray(): array
    {
        $version = [];
        if (defined('PHP_VERSION')) {
            $version = explode('.', PHP_VERSION);
        } elseif (function_exists('phpversion')) {
            $version = explode('.', phpversion());
        }

        return $version;
    }
}
