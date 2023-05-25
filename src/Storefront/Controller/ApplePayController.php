<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\ContextService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApplePayController extends AbstractPaymentController
{
    protected ContextService $contextService;

    protected LoggerInterface $logger;

    /**
     * @var AbstractShippingMethodRoute
     */
    private $shippingMethodRoute;

    private EntityRepository $shippingMethodRepository;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        ContextService $contextService,
        LoggerInterface $logger,
        SettingsService $settingsService,
        AbstractShippingMethodRoute $shippingMethodRoute,
        EntityRepository $shippingMethodRepository,
        SalesChannelRepository $paymentMethodRepository
    ) {
        $this->contextService = $contextService;
        $this->logger = $logger;
        $this->shippingMethodRoute = $shippingMethodRoute;
        $this->shippingMethodRepository = $shippingMethodRepository;
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
    #[Route(path: '/buckaroo/apple/cart/get', name: 'frontend.action.buckaroo.appleGetCart', options:['seo' =>false], methods:['POST'], defaults:['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
    public function getAppleCart(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

        try {
            $cart = $this->getCart($request, $salesChannelContext);

            $fee = $this->getFee($salesChannelContext, 'applepayFee');
            return $this->response([
                "cartToken" => $cart->getToken(),
                "storeName" => $this->contextService->getStoreName($salesChannelContext),
                "country" => $this->contextService->getCountryCode($salesChannelContext),
                "currency" => $this->contextService->getCurrencyCode($salesChannelContext),
                "lineItems" => $this->getLineItems($cart, $fee),
                "totals" => $this->getTotal($cart, $fee),
                "shippingMethods" => $this->getFormatedShippingMethods($cart, $salesChannelContext)
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    #[Route("/buckaroo/apple/cart/update", name:"frontend.action.buckaroo.appleUpdateCart", options:["seo"=>false], methods:["POST"], defaults:["XmlHttpRequest"=>true])]
    public function updateCart(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        if (!$request->request->has('cartToken')) {
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }

        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'ApplePayPaymentHandler');

            $cart = $this->getCartByToken(
                $request->request->get('cartToken'),
                $salesChannelContext
            );

            if ($cart === null) {
                return $this->response(
                    ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                    true
                );
            }


            if ($request->request->has('shippingMethod')) {
                $cart = $this->updateCartWithSelectedShipping(
                    $cart,
                    $request->request->get('shippingMethod'),
                    $salesChannelContext
                );
            }

            if ($request->request->has('shippingContact')) {
                $this->loginCustomer(
                    $this->getCustomerData((array)$request->request->get('shippingContact')),
                    $salesChannelContext
                );
                $cart = $this->cartService->calculateCart($cart, $salesChannelContext);
            }


            $fee = $this->getFee($salesChannelContext, 'applepayFee');
            return $this->response([
                "newLineItems" => $this->getLineItems($cart, $fee),
                "newTotal" => $this->getTotal($cart, $fee),
                "newShippingMethods" => $this->getFormatedShippingMethods($cart, $salesChannelContext),
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    #[Route("/buckaroo/apple/order/create", name:"frontend.action.buckaroo.appleCreateOrder", options:["seo" => false], methods:["POST"], defaults:["XmlHttpRequest"=>true])]
    public function createAppleOrder(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

        $this->overrideChannelPaymentMethod($salesChannelContext, 'ApplePayPaymentHandler');
        try {
            $redirectPath = $this->placeOrder(
                $this->createOrder($salesChannelContext, $request),
                $salesChannelContext,
                new RequestDataBag([
                    "applePayInfo" => $request->request->get('payment')
                ])
            );


            return $this->response([
                "redirect" => $this->getFinishPage($redirectPath)
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * Create order from cart
     *
     * @param SalesChannelContext $salesChannelContext
     * @param Request $request
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    protected function createOrder(SalesChannelContext $salesChannelContext, Request $request)
    {

        $cartToken = $request->request->get('cartToken');

        if (!is_string($cartToken)) {
            $cartToken = $salesChannelContext->getToken();
        }

        $cart = $this->getCartByToken($cartToken, $salesChannelContext);

        if ($cart === null) {
            throw new \Exception("Cannot find cart", 1);
        }

        if (in_array($request->request->get('page'), ['product', 'cart'])) {
            $updatedCart = $this->updateCartBillingAddress(
                $cart,
                $salesChannelContext,
                $request->request->get('payment')
            );

            if ($updatedCart !== null) {
                $cart = $updatedCart;
            }
        }

        $order = $this->orderService
            ->setSaleChannelContext($salesChannelContext)
            ->persist($cart);

        if ($order === null) {
            throw new \Exception("Cannot create order", 1);
        }
        return $order;
    }


    /**
     * @param Cart $cart
     * @param mixed $shippingMethodId
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart
     */
    protected function updateCartWithSelectedShipping(
        Cart $cart,
        $shippingMethodId,
        SalesChannelContext $salesChannelContext
    ): Cart {
        if (!is_string($shippingMethodId)) {
            throw new \InvalidArgumentException('Shipping method id must be a string');
        }

        $shippingMethod = $this->getShippingMethodById(
            $shippingMethodId,
            $salesChannelContext
        );

        if ($shippingMethod === null) {
            throw new \Exception($this->trans("buckaroo.button_payment.unknown_error"));
        }

        return $this->calculateCartShippingAmountForShippingMethod(
            $cart,
            $salesChannelContext,
            $shippingMethod
        );
    }

    /**
     *
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $paymentData
     *
     * @return Cart|null
     */
    protected function updateCartBillingAddress(
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        $paymentData
    ): ?Cart {
        if (is_string($paymentData)) {
            $paymentData = json_decode($paymentData, true);
        }

        if (!is_array($paymentData) || !isset($paymentData['billingContact'])) {
            return null;
        }

        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            throw new \InvalidArgumentException('Customer cannot be null');
        }

        $address = $this->customerService
        ->setSaleChannelContext($salesChannelContext)
        ->createAddress(
            $this->getCustomerData($paymentData['billingContact']),
            $customer
        );


        if ($address !== null) {
            $customer->setActiveBillingAddress($address);
            return $this->cartService->calculateCart($cart, $salesChannelContext);
        }
        return $cart;
    }
    /**
     * Get cart line items from
     *
     * @param Cart $cart
     * @return array<mixed>
     */
    public function getLineItems(Cart $cart, float $fee)
    {
        $shippingSum = $cart->getDeliveries()->getShippingCosts()->sum();
        $productSum = $cart->getLineItems()->getPrices()->sum();

        return [
            [
                'label' => $this->trans('bkr-applepay.Subtotal'),
                'amount' => $this->formatNumber(
                    $productSum->getTotalPrice() + $fee
                ),
                'type' => 'final'
            ],
            [
                'label' => $this->trans('bkr-applepay.Deliverycosts'),
                'amount' => $this->formatNumber($shippingSum->getUnitPrice()),
                'type' => 'final'
            ]
        ];
    }

    /**
     * Get cart total;
     *
     * @param Cart $cart
     * @return array<mixed>
     */
    public function getTotal(Cart $cart, float $fee): array
    {
        return  [
            'label' => $this->trans('checkout.summaryTotalPrice'),
            'amount' => $this->formatNumber(
                $cart->getPrice()->getTotalPrice() + $fee
            ),
            'type' => 'final'
        ];
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array<mixed>
     */
    public function getFormatedShippingMethods(Cart $cart, SalesChannelContext $salesChannelContext): array
    {
        $shippingMethodsCollection = $this->getShippingMethods($salesChannelContext);
        $shippingMethods = [];

        $currentShippingMethod = $salesChannelContext->getShippingMethod();

        foreach ($shippingMethodsCollection as $shippingMethod) {
            $amount = $this->calculateCartShippingAmountForShippingMethod(
                $cart,
                $salesChannelContext,
                $shippingMethod
            )->getShippingCosts()->getTotalPrice();

            $shippingMethods[] = [
                'label' => $shippingMethod->getName(),
                'amount' => $amount,
                'identifier' => $shippingMethod->getId(),
                'detail' => $shippingMethod->getDescription()
            ];
        }

        // Restore cart & context to the original payment method
        $this->calculateCartShippingAmountForShippingMethod(
            $cart,
            $salesChannelContext,
            $currentShippingMethod
        );

        return $shippingMethods;
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     * @param ShippingMethodEntity $shippingMethod
     *
     * @return Cart
     */
    public function calculateCartShippingAmountForShippingMethod(
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        ShippingMethodEntity $shippingMethod
    ): Cart {
        $salesChannelContext->assign([
            'shippingMethod' => $shippingMethod
        ]);

        return $this->cartService->calculateCart($cart, $salesChannelContext);
    }


    /**
     * @param SalesChannelContext $salesChannelContext
     *
     * @return ShippingMethodCollection
     */
    protected function getShippingMethods(SalesChannelContext $salesChannelContext): ShippingMethodCollection
    {

        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        /** @var \Shopware\Core\Checkout\Shipping\ShippingMethodCollection */
        return $this->shippingMethodRoute
            ->load($request, $salesChannelContext, new Criteria())
            ->getShippingMethods();
    }

    /**
     * Get shipping method by id
     *
     * @param string $shippingMethodId
     * @param SalesChannelContext $salesChannelContext
     *
     * @return ShippingMethodEntity|null
     */
    protected function getShippingMethodById(string $shippingMethodId, SalesChannelContext $salesChannelContext)
    {
        $criteria = new Criteria([$shippingMethodId]);

        return $this->shippingMethodRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();
    }

    /**
     * Get customer data from request
     *
     * @param array<mixed> $contactData
     *
     * @return DataBag
     */
    protected function getCustomerData(array $contactData)
    {
        $mappings = [
            'givenName' => 'first_name',
            'familyName' => 'last_name',
            'postalCode' => 'postal_code',
            'addressLines' => 'street',
            'locality' => 'city',
            'countryCode' => 'country_code'
        ];

        $data = [];
        foreach ($contactData as $key => $value) {
            if (isset($mappings[$key])) {
                $data[$mappings[$key]] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return new DataBag($data);
    }
}
