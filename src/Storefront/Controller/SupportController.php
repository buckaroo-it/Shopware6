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
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * @RouteScope(scopes={"api"})
 */
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
     * @Route("/api/_action/buckaroo/version", name="api.action.buckaroo.version", methods={"POST","GET"})
     *
     * @return JsonResponse
     */
    public function versionSupportBuckaroo(): JsonResponse
    {
        $phpVersion = $this->getPhpVersionArray();
        return new JsonResponse([
            'phpversion'          => implode('.', $phpVersion),
            'isPhpVersionSupport' => ($phpVersion[0] . $phpVersion[1] >= '71') ? true : false,
        ]);
    }


    /**
     * @Route("/api/_action/buckaroo/taxes", name="api.action.buckaroo.tax", methods={"POST"})
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
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
     * phpcs:ignore Generic.Files.LineLength.TooLong
     * @Route("/api/_action/buckaroo/getBuckarooTransaction", name="api.action.buckaroo.support.version", methods={"POST"})
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     */
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
     * @Route("/api/_action/buckaroo/getBuckarooApiTest", name="api.action.buckaroo.support.apitest", methods={"POST"})
     * @param Request $request
     *
     * @return JsonResponse
     */
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
