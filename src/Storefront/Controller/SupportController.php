<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    protected $checkoutHelper;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $taxRepository;

    
    public function __construct(
        CheckoutHelper $checkoutHelper,
        EntityRepository $taxRepository
    ) {
        $this->checkoutHelper = $checkoutHelper;
        $this->taxRepository = $taxRepository;
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
     * @Route("/api/_action/buckaroo/taxes", name="api.action.buckaroo.tax", methods={"POST"})
     * @param Request $request
     * @param Context $context
     *
     * @return RedirectResponse
     */
    public function getTaxes(Request $request, Context $context): JsonResponse
    {
        $taxes = $this->taxRepository->search(
            new Criteria(), $context
        )->getEntities();
        return new JsonResponse([
            'error' => false,
            'taxes' => $taxes
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

    /**
     * @Route("/api/_action/buckaroo/getBuckarooPaymentMethods", name="api.action.buckaroo.paymentmethods", methods={"POST","GET"})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function getBuckarooPaymentMethods(Request $request): JsonResponse
    {
        return new JsonResponse([
            'paymentMethods'      => ['afterpay', 'Alipay', 'applepay', 'bancontactmrcash', 'belfius', 'Billink', 'creditcard', 'creditcards', 'eps', 'giftcards', 'ideal', 'idealprocessing', 'capayable', 'KBCPaymentButton', 'klarna', 'klarnakp', 'klarnain', 'Przelewy24', 'payconiq', 'paypal', 'payperemail', 'sepadirectdebit', 'sofortueberweisung', 'transfer', 'Trustly', 'visa', 'WeChatPay']
        ]);
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
