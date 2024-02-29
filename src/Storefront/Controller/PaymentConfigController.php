<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Buckaroo\Shopware6\Service\Config\PageFactory;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Service\Rest\PaymentConfigResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class PaymentConfigController extends StorefrontController
{
   
    protected PageFactory $pageFactory;

    public function __construct(PageFactory $pageFactory)
    {
        $this->pageFactory = $pageFactory;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return PaymentConfigResponse
     */
    #[Route(
        path: '/store-api/buckaroo/payment/config',
        name: 'store-api.action.buckaroo.payment.config',
        methods: ['POST']
    )]
    public function get(Request $request, SalesChannelContext $salesChannelContext): PaymentConfigResponse
    {

        $struct = $this->pageFactory->get($salesChannelContext, 'checkout');
        return new PaymentConfigResponse($struct);
    }
}
