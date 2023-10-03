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
            $redirectPath = $this->placeOrder(
                $this->createOrder($salesChannelContext, (string)$request->request->get('cartToken')),
                $salesChannelContext,
                new RequestDataBag([
                    "orderId" => $request->request->get('orderId')
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
     * @param string|null $cartToken
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    protected function createOrder(SalesChannelContext $salesChannelContext, string $cartToken = null): OrderEntity
    {

        if (!is_string($cartToken)) {
            $cartToken = $salesChannelContext->getToken();
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

        $customer = $request->request->get('customer');

        if (!isset($customer['shipping_address'])) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }
        $dataBag = new DataBag((array)$customer['shipping_address']);
        $dataBag->set('paymentToken', $customer['paymentToken']);

        return $dataBag;
    }
}
