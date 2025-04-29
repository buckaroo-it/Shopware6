<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class IdealFastCheckoutController extends AbstractPaymentController
{
    private LoggerInterface $logger;
    private SalesChannelContextPersister $contextPersister;
    private SalesChannelContextService $contextService;
    private RegisterRoute $registerRoute;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $salutationRepository;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SettingsService $settingsService,
        SalesChannelRepository $paymentMethodRepository,
        LoggerInterface $logger,
        SalesChannelContextPersister $contextPersister,
        SalesChannelContextService $contextService,
        RegisterRoute $registerRoute,
        EntityRepository $salutationRepository
    ) {
        parent::__construct($cartService, $customerService, $orderService, $settingsService, $paymentMethodRepository);
        $this->logger = $logger;
        $this->contextPersister = $contextPersister;
        $this->contextService = $contextService;
        $this->registerRoute = $registerRoute;
        $this->salutationRepository = $salutationRepository;
    }
    #[Route(
        path: '/buckaroo/idealfastcheckout/pay',
        name: 'frontend.action.buckaroo.idealGetCart',
        options: ['seo' => false],
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']]
    )]
    public function pay(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'IdealPaymentHandler');

            if (!$salesChannelContext->getCustomer()) {
                $this->registerGuestCustomer($salesChannelContext);
            }

            $cart = $this->getCart($request, $salesChannelContext);
            $orderId = $this->createOrder($salesChannelContext, $cart->getToken());

            $redirectPath = $this->placeOrder(
                $orderId,
                $salesChannelContext,
                new RequestDataBag([
                    'idealFastCheckoutInfo' => true,
                    'page' => $request->request->get('page')
                ])
            );

            $this->cartService->deleteFromCart($salesChannelContext);

            $response = $this->response([
                'redirect' => $redirectPath
            ]);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            return $this->response(['message' => $e->getMessage()], true);
        }
    }


    /**
     * Create order from cart
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $cartToken
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     * @throws \Exception
     */
    protected function createOrder(SalesChannelContext $salesChannelContext, string $cartToken)
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
    private function registerGuestCustomer(SalesChannelContext $context): void
    {
        $email = 'guest_' . uniqid() . '@buckaroo.test';
        $country = $context->getShippingLocation()->getCountry();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified')); // use 'mr'/'ms' or the one you want
        $salutationId = $this->salutationRepository->search($criteria, $context->getContext())->first()?->getId();

        $data = new RequestDataBag([
            'guest' => true,
            'salutationId' => $salutationId,
            'firstName' => 'Guest',
            'lastName' => 'User',
            'email' => $email,
            'country_code' => 'NL',
            'city' => 'Guest City',
            'paymentToken' => $context->getToken(),
            'billingAddress' => [
                'firstName' => 'Guest',
                'lastName' => 'User',
                'street' => 'Guest Street 1',
                'zipcode' => '12345',
                'city' => 'Guest City',
                'countryId' => $country->getId(),
            ],
            'shippingAddress' => [
                'firstName' => 'Guest',
                'lastName' => 'User',
                'street' => 'Guest Street 1',
                'zipcode' => '12345',
                'city' => 'Guest City',
                'countryId' => $country->getId(),
            ]
        ]);
//
//        $this->loginCustomer(
//            $data,
//            $context
//        );
         $val = $this->registerRoute->register($data, $context, false)->getCustomer();
        var_dump($val   );
        die();
    }
}
