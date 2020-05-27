<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;

class SupportController extends StorefrontController
{

    public function __construct(
        CheckoutHelper $checkoutHelper
    ) {
        $this->checkoutHelper = $checkoutHelper;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/v{version}/_action/buckaroo/support/version", name="api.action.buckaroo.support.version", methods={"POST"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function versionSupportBuckaroo(Request $request): JsonResponse
    {
        $phpVersion = $this->getPhpVersionArray();
        return new JsonResponse([
            'phpversion' => implode('.', $phpVersion),
            'isPhpVersionSupport' => ($phpVersion[0] . $phpVersion[1] >= '71') ? true: false
        ]);
    }

    private function getPhpVersionArray()
    {
        $version = false;
        if (defined('PHP_VERSION')) {
            $version = explode('.', PHP_VERSION);
        }
        elseif (function_exists('phpversion')){
            $version = explode('.', phpversion());
        }

        return $version;
    }
}