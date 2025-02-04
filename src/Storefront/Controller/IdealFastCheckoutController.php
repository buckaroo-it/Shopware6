<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\ContextService;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;

class IdealFastCheckoutController extends AbstractPaymentController
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    protected ContextService $contextService;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SalesChannelRepository $paymentMethodRepository,
        SettingsService $settingsService,
        LoggerInterface $logger,
        ContextService $contextService,
    ) {
        $this->logger = $logger;
        $this->contextService = $contextService;

        parent::__construct(
            $cartService,
            $customerService,
            $orderService,
            $settingsService,
            $paymentMethodRepository
        );
    }
    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    #[Route(path: '/buckaroo/idealfastcheckout/pay', name: 'frontend.action.buckaroo.idealGetCart', options: ['seo' => false], methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
    public function pay(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'IdealPaymentHandler');

            $customer = $salesChannelContext->getCustomer();

            if (!$customer) {
                return $this->response([
                    "status" => "FAILED",
                    "message" => "No customer information available. Please log in to continue.",
                    "redirect" => $this->generateUrl('frontend.account.login.page')
                ], true);
            }

            $cart = $this->getCart($request, $salesChannelContext);

            $redirectPath = $this->placeOrder(
                $this->createOrder($salesChannelContext, $cart->getToken()),
                $salesChannelContext,
                new RequestDataBag([
                    "idealFastCheckoutInfo" => true,
                    "page" => $request->request->get('page')
                ])
            );

            if ($redirectPath == null) {
                return $this->response([
                    "status" => "FAILED",
                    "message" => "Something went wrong",
                    "reload" => true
                ], true);
            }

            $this->cartService->deleteFromCart($salesChannelContext);

            return $this->response([
                "redirect" => $redirectPath
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ["message" => $th],
                true
            );
        }
    }

    /**
     * Create order from cart
     *
     * @param SalesChannelContext $salesChannelContext
     * @param String $cartToken
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    protected function createOrder(SalesChannelContext $salesChannelContext, String $cartToken)
    {
        $cart = $this->getCartByToken($cartToken, $salesChannelContext);
        if ($cart === null) {
            throw new \Exception("Cannot find cart", 1);
        }
        $order = $this->orderService
            ->setSaleChannelContext($salesChannelContext)
            ->persist($cart);

        if ($order === null) {
            throw new \Exception("Cannot create order", 1);
        }
        return $order;
    }
}
