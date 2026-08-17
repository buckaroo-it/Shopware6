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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;

class PaypalExpressController extends AbstractPaymentController
{
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
        $this->logger = $logger;

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
     *
     * @return JsonResponse
     */
    #[Route(path: "buckaroo/paypal/create", defaults: ['_routeScope' => ['storefront'], "XmlHttpRequest" => true], options: ["seo" => false], name: "frontend.action.buckaroo.paypalExpressCreate", methods: ["POST"])]
    public function create(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {


        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'PaypalPaymentHandler');

            $this->loginCustomer(
                $this->getCustomerData($request),
                $salesChannelContext
            );
            $cart = $this->getCart($request, $salesChannelContext);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }

        return $this->response([
            "cart" => $this->getCartBreakdown($cart, $salesChannelContext),
            "token" => $cart->getToken(),
        ]);
    }



    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    #[Route(path: "buckaroo/paypal/pay", defaults: ['_routeScope' => ['storefront'], "XmlHttpRequest" => true], options: ["seo" => false], name: "frontend.action.buckaroo.paypalExpressPay", methods: ["POST"])]
    public function pay(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $this->overrideChannelPaymentMethod($salesChannelContext, 'PaypalPaymentHandler');
        if (!$request->request->has('orderId')) {
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.missing_order_id")],
                true
            );
        }

        try {
            $requestCartToken = $request->request->get('cartToken');
            $cartToken = is_string($requestCartToken) && $requestCartToken !== ''
                ? $requestCartToken
                : $salesChannelContext->getToken();

            $redirectPath = $this->placeOrder(
                $this->createOrder($salesChannelContext, $cartToken),
                $salesChannelContext,
                new RequestDataBag([
                    "orderId" => $request->request->get('orderId'),
                    "paypalExpressInfo" => true
                ])
            );

            $redirect = $this->getFinishPage($redirectPath);

            if ($redirect !== null) {
                // Order placed and payment initiated: delete the cart. This custom
                // express order path bypasses Shopware's CartOrderRoute, which is where
                // the cart is normally removed after checkout - without this the paid
                // cart is still there when the shopper returns to the shop.
                $this->deleteCartAfterOrder($cartToken, $salesChannelContext);
            }

            return $this->response([
                "redirect" => $redirect
            ]);
        } catch (\Throwable $th) {
            // error level: debug is not written in production, which hides the
            // real cause behind the generic "unknown error" JSON response.
            $this->logger->error('[PaypalExpress] pay failed: ' . (string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * Delete the cart that was just converted into an order. The cart-page flow uses
     * the session cart; the product-page flow uses its own temporary cart, so the
     * shopper's session cart is left untouched there. Cleanup must never fail a
     * successful payment.
     */
    private function deleteCartAfterOrder(string $cartToken, SalesChannelContext $salesChannelContext): void
    {
        try {
            $this->cartService->deleteCartByToken($cartToken, $salesChannelContext);
        } catch (\Throwable $th) {
            $this->logger->warning('[PaypalExpress] could not delete cart after order: ' . $th->getMessage());
        }
    }

    /**
     * Create order from cart
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $cartToken
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    protected function createOrder(SalesChannelContext $salesChannelContext, string $cartToken): OrderEntity
    {
        $cart = $this->getCartByToken($cartToken, $salesChannelContext);

        if ($cart === null) {
            throw new \Exception("Cannot find cart", 1);
        }

        // Express flow: /buckaroo/paypal/create - and with it the guest login - only
        // runs when PayPal fires a shipping change. When it does not (single saved
        // address, no-shipping cart, newer SDK callback naming) this request still
        // carries the anonymous context, the cart delivery has a country-only
        // shipping location and OrderPersister throws
        // "Delivery contains no shipping address". Create and log in a guest here;
        // the real payer name/address/email is written back onto the order by
        // UpdateOrderWithPaypalExpressData once Buckaroo responds.
        if ($salesChannelContext->getCustomer() === null) {
            $this->customerService->createGuestCustomer($salesChannelContext);
        }

        // Recalculate so the delivery picks up the shipping address that was assigned
        // to the context above; the persisted cart was calculated anonymously.
        $cart = $this->cartService
            ->setSaleChannelContext($salesChannelContext)
            ->calculateCart($cart, $salesChannelContext);

        $order = $this->orderService
            ->setSaleChannelContext($salesChannelContext)
            ->persist($cart);

        if ($order === null) {
            throw new \Exception("Cannot create order", 1);
        }
        return $order;
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

        $fee = $this->getFee($salesChannelContext, 'paypalFee');
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

        $customer = $request->request->all()['customer'];

        if (!isset($customer['shipping_address'])) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }
        $dataBag = new DataBag((array)$customer['shipping_address']);
        $dataBag->set('paymentToken', $customer['paymentToken']);

        return $dataBag;
    }
}
