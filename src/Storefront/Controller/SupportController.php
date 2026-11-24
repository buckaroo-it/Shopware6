<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\BuckarooTransactionService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\TestCredentialsService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SupportController extends StorefrontController
{
    /**
     * @var EntityRepository
     */
    protected $taxRepository;

    protected TestCredentialsService $testCredentialsService;

    protected BuckarooTransactionService $buckarooTransactionService;

    protected In3LogoService $in3LogoService;

    protected string $shopwareVersion;

    public function __construct(
        TestCredentialsService $testCredentialsService,
        BuckarooTransactionService $buckarooTransactionService,
        EntityRepository $taxRepository,
        In3LogoService $in3LogoService,
        string $shopwareVersion
    ) {
        $this->testCredentialsService = $testCredentialsService;
        $this->buckarooTransactionService = $buckarooTransactionService;
        $this->taxRepository = $taxRepository;
        $this->in3LogoService = $in3LogoService;
        $this->shopwareVersion = $shopwareVersion;
    }

    #[Route(path: '/api/_action/buckaroo/version', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['system_config:read']], name: 'api.action.buckaroo.version', methods: ['POST', 'GET'])]
    public function versionSupportBuckaroo(): JsonResponse
    {
        $phpVersion = $this->getPhpVersionArray();

        return new JsonResponse([
            'phpversion' => implode('.', $phpVersion),
            'isPhpVersionSupport' => ($phpVersion[0] . $phpVersion[1] >= '71') ? true : false,
            'shopware_version' => $this->shopwareVersion,
        ]);
    }

    #[Route(path: '/api/_action/buckaroo/taxes', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['tax:read']], name: 'api.action.buckaroo.tax', methods: ['POST'])]
    public function getTaxes(Request $request, Context $context): JsonResponse
    {
        $taxes = $this->taxRepository->search(
            new Criteria(),
            $context
        )->getEntities();

        return new JsonResponse([
            'error' => false,
            'taxes' => $taxes,
        ]);
    }

    /**
     * @return JsonResponse
     */
    #[Route(path: '/api/_action/buckaroo/getBuckarooTransaction', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['order.viewer']], name: 'api.action.buckaroo.support.version', methods: ['POST'])]
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

    #[Route(path: '/api/_action/buckaroo/getBuckarooApiTest', defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['system_config:update', 'system_config:create', 'system_config:delete']], name: 'api.action.buckaroo.support.apitest', methods: ['POST'])]
    public function getBuckarooApiTest(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->testCredentialsService->execute($request)
        );
    }

    #[Route(
        path: '/api/_action/buckaroo/in3/logos',
        defaults: ['_routeScope' => ['api'], 'auth_required' => true, '_acl' => ['payment_method:read']],
        name: 'api.action.buckaroo.in3.logos',
        methods: ['POST']
    )]
    public function getIn3Logos(Context $context): JsonResponse
    {
        return new JsonResponse(
            ['logos' => $this->in3LogoService->getLogos($context)]
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
