<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Service\ContextService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class IdealFastCheckoutController extends AbstractPaymentController
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    protected ContextService $contextService;
    /**
     * @var AbstractShippingMethodRoute
     */
    private $shippingMethodRoute;

    private EntityRepository $shippingMethodRepository;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SalesChannelRepository $paymentMethodRepository,
        SettingsService $settingsService,
        LoggerInterface $logger,
        ContextService $contextService,
        AbstractShippingMethodRoute $shippingMethodRoute,
        EntityRepository $shippingMethodRepository
    ) {
        $this->logger = $logger;
        $this->contextService = $contextService;
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
                'label' => $this->trans('bkr-idealfastcheckout.Subtotal'),
                'amount' => $this->formatNumber(
                    $productSum->getTotalPrice() + $fee
                ),
                'type' => 'final'
            ],
            [
                'label' => $this->trans('bkr-idealfastcheckout.Deliverycosts'),
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
     * Create order from cart
     *
     * @param SalesChannelContext $salesChannelContext
     * @param Request $request
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
     * Get cart price breakdown
     *
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array<mixed>
     */
    protected function getCartBreakdown(Cart $cart, SalesChannelContext $salesChannelContext): array
    {
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $price = $cart->getPrice();

        $shippingSum = $cart->getDeliveries()->getShippingCosts()->sum();
        $productSum = $cart->getLineItems()->getPrices()->sum();

        $fee = $this->getFee($salesChannelContext, 'idealFee');
        return [
            "breakdown" => [
                "item_total" => [
                    "currency_code" => $currency,
                    "value" => $this->formatNumber(
                        $productSum->getTotalPrice() - $productSum->getCalculatedTaxes()->getAmount()
                    )
                ],
                "shipping" => [
                    "currency_code" => $currency,
                    "value" => $this->formatNumber(
                        $shippingSum->getUnitPrice() - $shippingSum->getCalculatedTaxes()->getAmount()
                    )
                ],
                "tax_total" => [
                    "currency_code" => $currency,
                    "value" => $this->formatNumber($price->getCalculatedTaxes()->getAmount() + $fee)
                ]
            ],
            "currency_code" => $currency,
            "value" => $this->formatNumber($price->getTotalPrice() + $fee),
        ];
    }
    /**
     * Get customer data from request
     *
     * @param Request $request
     *
     * @return DataBag
     * @throws InvalidParameterException
     */
    protected function getCustomerData(Request $request)
    {
        if (!$request->request->has('customer')) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }


        $customer = $request->request->get('customer');

        if (!isset($customer['shipping_address'])) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }
        $dataBag = new DataBag((array)$customer['shipping_address']);
        $dataBag->set('paymentToken', $customer['paymentToken']);

        return $dataBag;
    }
}
