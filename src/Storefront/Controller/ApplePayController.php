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
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;


/**
 * @RouteScope(scopes={"storefront"})
 */
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
     * @Route("/buckaroo/apple/cart/get", name="frontend.action.buckaroo.appleGetCart", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function getAppleCart(Request $request, SalesChannelContext $salesChannelContext)
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
                ["message" => $this->trans("buckaroo-payment.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * @Route("/buckaroo/apple/cart/update", name="frontend.action.buckaroo.appleUpdateCart", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function updateCart(Request $request, SalesChannelContext $salesChannelContext)
    {
        if (!$request->request->has('cartToken')) {
            return $this->response(
                ["message" => $this->trans("buckaroo-payment.button_payment.unknown_error")],
                true
            );
        }

        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'ApplePayPaymentHandler');

            $cart = $this->getCartByToken(
                $request->request->get('cartToken'),
                $salesChannelContext
            );

            if ($request->request->has('shippingMethod')) {
                $cart = $this->updateCartWithSelectedShipping(
                    $cart,
                    $request->request->get('shippingMethod'),
                    $salesChannelContext
                );
            }

            if ($request->request->has('shippingContact')) {
                $this->loginCustomer(
                    $this->getCustomerData($request->request->get('shippingContact')),
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
                ["message" => $this->trans("buckaroo-payment.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * @Route("/buckaroo/apple/order/create", name="frontend.action.buckaroo.appleCreateOrder", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function createAppleOrder(Request $request, SalesChannelContext $salesChannelContext)
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
                ["message" => $this->trans("buckaroo-payment.button_payment.unknown_error")],
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

            if($updatedCart !== null) {
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


    protected function updateCartWithSelectedShipping(Cart $cart, string $shippingMethodId, SalesChannelContext $salesChannelContext)
    {
        $shippingMethod = $this->getShippingMethodById(
            $shippingMethodId,
            $salesChannelContext
        );

        if ($shippingMethod === null) {
            return $this->response(
                ["message" => $this->trans("buckaroo-payment.button_payment.unknown_error")],
                true
            );
        }

        return $this->calculateCartShippingAmountForShippingMethod(
            $cart,
            $salesChannelContext,
            $shippingMethod
        );
    }

    protected function updateCartBillingAddress(Cart $cart, SalesChannelContext $salesChannelContext, $paymentData)
    {
        if (is_string($paymentData)) {
            $paymentData = json_decode($paymentData, true);
        }

        if (!is_array($paymentData) || !isset($paymentData['billingContact'])) {
            return;
        }

        $address = $this->customerService
        ->setSaleChannelContext($salesChannelContext)
        ->createAddress(
            $this->getCustomerData($paymentData['billingContact']),
            $salesChannelContext->getCustomer()
        );


        if ($address !== null) {
            $salesChannelContext
                ->getCustomer()
                ->setActiveBillingAddress($address);
            return $this->cartService->calculateCart($cart, $salesChannelContext);
        }
        return $cart;
    }
    /**
     * Get cart line items from
     *
     * @param Cart $cart
     * @return array
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
     * @return void
     */
    public function getTotal(Cart $cart, float $fee)
    {
        return  [
            'label' => $this->trans('checkout.summaryTotalPrice'),
            'amount' => $this->formatNumber(
                $cart->getPrice()->getTotalPrice() + $fee
            ),
            'type' => 'final'
        ];
    }
    public function getFormatedShippingMethods(Cart $cart, SalesChannelContext $salesChannelContext)
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

    public function calculateCartShippingAmountForShippingMethod(
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        ShippingMethodEntity $shippingMethod
    ) {
        $salesChannelContext->assign([
            'shippingMethod' => $shippingMethod
        ]);

        return $this->cartService->calculateCart($cart, $salesChannelContext);
    }


    protected function getShippingMethods(SalesChannelContext $salesChannelContext)
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
     * @param array $contactData
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
