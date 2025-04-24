<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\ContextService;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;

class IdealFastCheckoutController extends AbstractPaymentController
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    protected ContextService $contextService;
    private SalesChannelContextPersister $contextPersister;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SalesChannelRepository $paymentMethodRepository,
        SettingsService $settingsService,
        LoggerInterface $logger,
        ContextService $contextService,
        SalesChannelContextPersister $contextPersister,
    ) {
        $this->logger = $logger;
        $this->contextService = $contextService;
        $this->contextPersister = $contextPersister;

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
            // 1. Create and login dummy customer
            if (!$salesChannelContext->getCustomer()) {
                $dummyCustomer = $this->loginCustomer(
                    $this->createDummyGuestCustomer($salesChannelContext),
                    $salesChannelContext
                );

                // 2. Save customer into context so Shopware knows it
                $this->contextPersister->save(
                    $salesChannelContext->getToken(),
                    ['customerId' => $dummyCustomer->getId()],
                    $salesChannelContext->getSalesChannel()->getId()
                );
            }

            // 3. Continue with cart and order creation
            $cart = $this->getCart($request, $salesChannelContext);
            $orderId = $this->createOrder($salesChannelContext, $cart->getToken());

            $redirectPath = $this->placeOrder(
                $orderId,
                $salesChannelContext,
                new RequestDataBag([
                    "idealFastCheckoutInfo" => true,
                    "page" => $request->request->get('page')
                ])
            );

            if (!$redirectPath) {
                return $this->response([
                    "status" => "FAILED",
                    "message" => "Something went wrong",
                    "reload" => true
                ], true);
            }

            $this->cartService->deleteFromCart($salesChannelContext);

            $response = $this->response([
                'redirect' => $redirectPath
            ]);
            return $response;
        } catch (\Throwable $th) {
            $this->logger->error((string)$th);
            return $this->response([
                "message" => $th->getMessage()
            ], true);
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
    protected function createDummyGuestCustomer(SalesChannelContext $salesChannelContext): DataBag
    {
        $email = 'guest_' . uniqid() . '@buckaroo.test';
        $country = $salesChannelContext->getShippingLocation()->getCountry();

        $data = [
            'guest' => true,
            'first_name' => 'Guest',
            'last_name' => 'User',
            'email' => $email,
            'country_code' => $country->getIso(),
            'city' => 'Guest City',
            'street' => 'Guest Street 1',
            'zipcode' => '12345',
            'storefrontUrl' => $salesChannelContext->getSalesChannel()->getDomains()->first()?->getUrl(),
            'paymentToken' => $salesChannelContext->getToken(), // optional but useful
            'shipping_address' => [
                'firstName' => 'Guest',
                'lastName' => 'User',
                'street' => 'Guest Street 1',
                'zipcode' => '12345',
                'city' => 'Guest City',
                'countryCode' => $country->getIso()
            ],
            'billingAddress' => [
                'firstName' => 'Guest',
                'lastName' => 'User',
                'street' => 'Guest Street 1',
                'zipcode' => '12345',
                'city' => 'Guest City',
                'countryCode' => $country->getIso()
            ],
        ];

        return new DataBag($data);
    }
}
