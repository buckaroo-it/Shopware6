<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PushController extends StorefrontController
{
    protected TypeFactory $factory;

    public function __construct(
        TypeFactory $factory
    ) {
        $this->factory = $factory;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    #[Route(
        path: "/buckaroo/test",
        defaults: ['_routeScope' => ['storefront']],
        options: ["seo" => false],
        name: "buckaroo.payment.test",
        methods: ["GET"]
    )]
    public function test(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

    }
}
