<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class SupportController extends StorefrontController
{
    protected $checkoutHelper;
    
    public function __construct(
        CheckoutHelper $checkoutHelper
    ) {
        $this->checkoutHelper = $checkoutHelper;
    }

    /**
     * @Route("/api/_action/buckaroo/version", name="api.action.buckaroo.version", methods={"POST","GET"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function versionSupportBuckaroo(Request $request): JsonResponse
    {
        $phpVersion = $this->getPhpVersionArray();
        return new JsonResponse([
            'phpversion'          => implode('.', $phpVersion),
            'isPhpVersionSupport' => ($phpVersion[0] . $phpVersion[1] >= '71') ? true : false,
        ]);
    }

    /**
     * @Route("/api/_action/buckaroo/getBuckarooTransaction", name="api.action.buckaroo.support.version", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    public function getBuckarooTransaction(Request $request)
    {
        $orderId = $request->get('transaction');
        $items   = $this->checkoutHelper->getBuckarooTransactionsByOrderId($orderId);
        return new JsonResponse($items);
    }

    /**
     * @Route("/api/_action/buckaroo/getBuckarooApiTest", name="api.action.buckaroo.support.apitest", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    public function getBuckarooApiTest(Request $request)
    {
        $websiteKeyId = $request->get('websiteKeyId');
        $secretKeyId = $request->get('secretKeyId');
        $saleChannelId = $request->get('saleChannelId');
        $res = $this->checkoutHelper->getBuckarooApiTest($websiteKeyId, $secretKeyId, $saleChannelId);
        return new JsonResponse($res);
    }

    private function getPhpVersionArray()
    {
        $version = false;
        if (defined('PHP_VERSION')) {
            $version = explode('.', PHP_VERSION);
        } elseif (function_exists('phpversion')) {
            $version = explode('.', phpversion());
        }

        return $version;
    }
}
