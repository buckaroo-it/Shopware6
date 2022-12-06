<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PaypalExpressController extends StorefrontController
{

    /**
     * @var \Buckaroo\Shopware6\Service\CartService
     */
    protected $cartService;

    /**
     * @var \Buckaroo\Shopware6\Service\CustomerService
     */
    protected $customerService;

    /**
     * @var \Buckaroo\Shopware6\Service\OrderService
     */
    protected $orderService;

    /**
     * @var \Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository
     */
    protected $paymentMethodRepository;

    /**
     * @var \Buckaroo\Shopware6\Service\SettingsService
     */
    protected $settingsService;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SalesChannelRepository $paymentMethodRepository,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->cartService = $cartService;
        $this->customerService = $customerService;
        $this->orderService = $orderService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }
    /**
     * @Route("buckaroo/paypal/create", name="frontend.action.buckaroo.paypalExpressCreate",  options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function create(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

        
        try {
            $this->overrideChannelPaymentMethod($salesChannelContext);
            $this->getCustomer($request, $salesChannelContext);
            $cart = $this->getCart($request, $salesChannelContext);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(["message" => "Unknown buckaroo error occurred"], true);
        }

        return $this->response([
            "cart" => $this->getCartBreakdown($cart, $salesChannelContext),
            "token" => $cart->getToken(),
        ]);
    }



    /**
     * @Route("buckaroo/paypal/pay", name="frontend.action.buckaroo.paypalExpressPay",  options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function pay(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $this->overrideChannelPaymentMethod($salesChannelContext);
        if (!$request->request->has('orderId')) {
            return $this->response(
                ["message" => "Paypal express order id is required"],
                true
            );
        }

        try {
            $redirectPath = $this->placeOrder(
                $this->createOrder($request, $salesChannelContext),
                $salesChannelContext,
                $request->request->get('orderId')
            );


            return $this->response([
                "redirect" => $this->getFinishPage($redirectPath)
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(["message" => "Unknown buckaroo error occurred"], true);
        }
    }

    /**
     * Get absolute url to the finish page after payment
     *
     * @param mixed $redirectPath
     *
     * @return string|null
     */
    protected function getFinishPage($redirectPath)
    {
        if (is_string($redirectPath)) {
            return $this->generateUrl('frontend.home.page',  [], UrlGeneratorInterface::ABSOLUTE_URL) . ltrim($redirectPath, "/");
        }
    }
    /**
     * Place order and do payment
     *
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $salesChannelContext
     * @param string $paypalOrderId
     *
     * @return string|null
     */
    protected function placeOrder(
        OrderEntity $orderEntity,
        SalesChannelContext $salesChannelContext,
        string $paypalOrderId
    ) {
        return $this->orderService
            ->setSaleChannelContext($salesChannelContext)
            ->place($orderEntity, $paypalOrderId);
    }

    /**
     * Create order from cart
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    protected function createOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $cartToken = $salesChannelContext->getToken();

        if ($request->request->has('cartToken')) {
            $cartToken = $request->request->get('cartToken');
        }

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
     * Get cart by token
     *
     * @param string $token
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart|null
     */
    protected function getCartByToken(string $token, SalesChannelContext $salesChannelContext)
    {
        return $this->cartService
            ->setSaleChannelContext($salesChannelContext)
            ->load($token);
    }

    /**
     * Get cart price breakdown
     *
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return * @return RedirectResponse
     */
    protected function getCartBreakdown(Cart $cart, SalesChannelContext $salesChannelContext)
    {
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $price = $cart->getPrice();

        $shippingSum = $cart->getDeliveries()->getShippingCosts()->sum();
        $productSum = $cart->getLineItems()->getPrices()->sum();
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
                    "value" => $this->formatNumber($price->getCalculatedTaxes()->getAmount() + $this->getFee($salesChannelContext))
                ]
            ],
            "currency_code" => $currency,
            "value" => $this->formatNumber($price->getTotalPrice() + $this->getFee($salesChannelContext)),
        ];
    }

    protected function formatNumber(float $number)
    {
        return number_format($number, 2);
    }
    /**
     * Return json response with data
     *
     * @param array $data
     * @param boolean $error
     *
     * @return JsonResponse
     */
    protected function response(array $data, $error = false): JsonResponse
    {
        $data = array_merge(
            [
                'error' => $error
            ],
            $data,
        );
        return new JsonResponse($data);
    }
    /**
     * Set paypal as payment method
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    protected function overrideChannelPaymentMethod(SalesChannelContext $salesChannelContext)
    {
        $paymentMethod = $this->getValidPaymentMethod($salesChannelContext);

        if ($paymentMethod === null) {
            throw new \Exception("Cannot set paypal payment method", 1);
        }
        $salesChannelContext->assign([
            'paymentMethod' => $paymentMethod
        ]);
    }

    protected function getCustomer(Request $request, SalesChannelContext $salesChannelContext)
    {
        return $this->customerService
            ->setSaleChannelContext($salesChannelContext)
            ->get(
                $this->getOrderData($request)
            );
    }

    /**
     * Get or create cart
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return \Shopware\Core\Checkout\Cart\Cart
     */
    protected function getCart(Request $request, SalesChannelContext $salesChannelContext)
    {

        if ($this->isFromProductPage($request)) {
            return $this->createCart($request, $salesChannelContext);
        }

        return $this->getCartByToken(
            $salesChannelContext->getToken(),
            $salesChannelContext
        );
    }

    /**
     * Create cart for product page
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return \Shopware\Core\Checkout\Cart\Cart
     */
    protected function createCart(Request $request, SalesChannelContext $salesChannelContext)
    {
        $productData = $this->getProductData(
            $this->getFormData($request)
        );
        return $this->cartService
            ->setSaleChannelContext($salesChannelContext)
            ->addItem($productData)
            ->build();
    }

    /**
     * Get form data form request
     *
     * @param Request $request
     *
     * @return DataBag
     */
    protected function getFormData(Request $request)
    {
        if (!$request->request->has('form')) {
            throw new InvalidParameterException("Invalid payment request, form data is missing", 1);
        }
        return new DataBag((array)$request->request->get('form'));
    }

    /**
     * Get product data from from data
     *
     * @param DataBag $formData
     *
     * @return array
     */
    protected function getProductData(DataBag $formData)
    {
        $productData = [];
        foreach ($formData as $key => $value) {
            if (strpos($key, 'lineItems') !== false) {
                $keyPars = explode("][", $key);
                $newKey = isset($keyPars[1]) ? str_replace("]", "", $keyPars[1]) : $key;
                $productData[$newKey] = $value;
            }
        }
        $keysRequired =  [
            "id",
            "quantity",
            "referencedId",
            "removable",
            "stackable",
            "type",
        ];

        if (!array_intersect($keysRequired, array_keys($productData)) == $keysRequired) {
            throw new InvalidParameterException("Invalid product parameters", 1);
        }



        return [
            "id" => $productData['id'],
            "quantity" => (int)$productData['quantity'],
            "referencedId" => $productData['referencedId'],
            "removable" => (bool)$productData['removable'],
            "stackable" => (bool)$productData['stackable'],
            "type" => $productData['type'],
        ];
    }
    /**
     * Check if request is from product page
     *
     * @param Request $request
     *
     * @return boolean
     * @throws InvalidParameterException
     */
    protected function isFromProductPage(Request $request)
    {
        if (!$request->request->has('page')) {
            throw new InvalidParameterException("Invalid payment request, page is missing", 1);
        }
        return $request->request->get('page') === 'product';
    }
    /**
     * Get address from paypal
     *
     * @param Request $request
     *
     * @return DataBag
     * @throws InvalidParameterException
     */
    protected function getAddress(Request $request)
    {
        $orderData = $this->getOrderData($request);
        if (!$orderData->has('shipping_address')) {
            throw new InvalidParameterException("Invalid payment request, shipping is missing", 1);
        }
        return $orderData->get('shipping');
    }

    /**
     * Get paypal order data from request
     *
     * @param Request $request
     *
     * @return DataBag
     * @throws InvalidParameterException
     */
    protected function getOrderData(Request $request)
    {
        if (!($request->request->has('order') && is_array($request->request->get('order')))) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }
        return new DataBag((array)$request->request->get('order'));
    }

    /**
     * Get paypal payment method
     *
     * @return \Shopware\Core\Checkout\Payment\PaymentMethodEntity|null
     */
    public function getValidPaymentMethod(SalesChannelContext $salesChannelContext)
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('handlerIdentifier', 'Buckaroo\Shopware6\Handlers\PaypalPaymentHandler'));

        return $this->paymentMethodRepository->search(
            $criteria,
            $salesChannelContext
        )
            ->first();
    }

    /**
     * Get paypal payment fee
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return float
     */
    public function getFee(SalesChannelContext $salesChannelContext)
    {
        $fee = $this->settingsService->getSetting('paypalFee', $salesChannelContext->getSalesChannelId());
        return round((float)str_replace(',', '.', $fee), 2);
    }
}
