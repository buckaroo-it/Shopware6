<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Buckaroo\Shopware6\Service\Config\CheckoutFactory;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RefundController extends StorefrontController
{
   
    private CheckoutFactory $checkoutFactory;

    public function __construct(CheckoutFactory $checkoutFactory)
    {
        $this->checkoutFactory = $checkoutFactory;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    #[Route(
        path: '/buckaroo/payment/config',
        name: 'frontend.action.buckaroo.payment.config',
        options: ['seo' => false], methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']]
    )]
    public function refundBuckaroo(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        return new JsonResponse(
            $this->checkoutFactory->get($salesChannelContext)
        );
    }
}
