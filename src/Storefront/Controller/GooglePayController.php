<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\ContextService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Symfony\Component\HttpFoundation\JsonResponse;

class GooglePayController extends AbstractPaymentController
{
    protected ContextService $contextService;

    protected LoggerInterface $logger;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        ContextService $contextService,
        LoggerInterface $logger,
        SettingsService $settingsService,
        SalesChannelRepository $paymentMethodRepository
    ) {
        $this->contextService = $contextService;
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
     */
    #[Route(path: '/buckaroo/googlepay/cart/get', name: 'frontend.action.buckaroo.googleGetCart', options: ['seo' => false], methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
    public function getGoogleCart(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        try {
            // On product page with form data → build a fresh single-product cart for express checkout.
            // On product page WITHOUT form data (element is outside a <form>) → use the existing cart.
            // On all other pages → use the existing cart.
            $isProductWithForm = $request->request->get('page') === 'product'
                && $request->request->get('form') !== null;

            if ($isProductWithForm) {
                $cart = $this->getCart($request, $salesChannelContext);
            } else {
                $cart = $this->getCartByToken(
                    $salesChannelContext->getToken(),
                    $salesChannelContext
                );
                if ($cart === null) {
                    throw new \Exception('Cannot retrieve cart', 1);
                }
            }

            $fee = $this->getFee($salesChannelContext, 'googlepayFee');

            $salesChannelId = $salesChannelContext->getSalesChannelId();
            $gatewayMerchantId = $this->settingsService->getSetting('googlepayGatewayMerchantId', $salesChannelId);
            if (!is_scalar($gatewayMerchantId) || (string)$gatewayMerchantId === '') {
                $gatewayMerchantId = $this->settingsService->getSetting('websiteKey', $salesChannelId);
            }

            return $this->response([
                'cartToken'         => $cart->getToken(),
                'storeName'         => $this->contextService->getStoreName($salesChannelContext),
                'country'           => $this->contextService->getCountryCode($salesChannelContext),
                'currency'          => $this->contextService->getCurrencyCode($salesChannelContext),
                'totalPrice'        => $this->formatNumber(
                    $cart->getPrice()->getTotalPrice() + $fee
                ),
                'gatewayMerchantId' => is_scalar($gatewayMerchantId) ? (string)$gatewayMerchantId : '',
            ]);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
            return $this->response(
                ['message' => $this->trans('buckaroo.button_payment.unknown_error')],
                true
            );
        }
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    #[Route(path: '/buckaroo/googlepay/order/create', name: 'frontend.action.buckaroo.googleCreateOrder', options: ['seo' => false], methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
    public function createGoogleOrder(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $this->logger->info('[GooglePay] createGoogleOrder — START', [
            'page'        => $request->request->get('page'),
            'cartToken'   => $request->request->get('cartToken'),
            'contextToken' => $salesChannelContext->getToken(),
            'customerId'  => $salesChannelContext->getCustomer()?->getId() ?? 'guest/null',
            'paymentHasInfo' => !empty($request->request->get('payment')),
        ]);

        try {
            $this->overrideChannelPaymentMethod($salesChannelContext, 'GooglePayPaymentHandler');
            $this->logger->info('[GooglePay] createGoogleOrder — payment method overridden to GooglePay');

            $order = $this->createOrder($salesChannelContext, $request);
            $this->logger->info('[GooglePay] createGoogleOrder — order created', [
                'orderId'     => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'amount'      => $order->getAmountTotal(),
            ]);

            $redirectPath = $this->placeOrder(
                $order,
                $salesChannelContext,
                new RequestDataBag([
                    'googlePayInfo' => $request->request->get('payment')
                ])
            );

            $this->logger->info('[GooglePay] createGoogleOrder — placeOrder returned', [
                'redirectPath' => $redirectPath,
            ]);

            $redirect = $this->getFinishPage($redirectPath);

            $this->logger->info('[GooglePay] createGoogleOrder — getFinishPage returned', [
                'redirect' => $redirect,
            ]);

            if ($redirect === null) {
                $this->logger->error('[GooglePay] createGoogleOrder — redirect is null after placeOrder. Check OrderService / PaymentProcessor logs above for the root cause.');
                return $this->response(
                    ['message' => $this->trans('buckaroo.button_payment.unknown_error')],
                    true
                );
            }

            $this->logger->info('[GooglePay] createGoogleOrder — SUCCESS, redirecting to: ' . $redirect);
            return $this->response(['redirect' => $redirect]);
        } catch (\Throwable $th) {
            $this->logger->error('[GooglePay] createGoogleOrder — EXCEPTION', [
                'message' => $th->getMessage(),
                'class'   => get_class($th),
                'file'    => $th->getFile() . ':' . $th->getLine(),
                'trace'   => $th->getTraceAsString(),
            ]);
            return $this->response(
                ['message' => $this->trans('buckaroo.button_payment.unknown_error')],
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
            throw new \Exception('Cannot find cart', 1);
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
            throw new \Exception('Cannot create order', 1);
        }

        return $order;
    }

    /**
     * Update cart billing address from Google Pay payment data
     *
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $paymentData
     *
     * @return \Shopware\Core\Checkout\Cart\Cart|null
     */
    protected function updateCartBillingAddress(
        \Shopware\Core\Checkout\Cart\Cart $cart,
        SalesChannelContext $salesChannelContext,
        $paymentData
    ): ?\Shopware\Core\Checkout\Cart\Cart {
        if (is_string($paymentData)) {
            $paymentData = json_decode($paymentData, true);
        }

        if (!is_array($paymentData) || !isset($paymentData['paymentMethodData'])) {
            return null;
        }

        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            throw new \InvalidArgumentException('Customer cannot be null');
        }

        // Google Pay billing address is inside paymentData (if requestBillingAddress was used)
        $billingAddress = $paymentData['billingAddress'] ?? null;
        if (!is_array($billingAddress)) {
            return null;
        }

        $address = $this->customerService
            ->setSaleChannelContext($salesChannelContext)
            ->createAddress(
                $this->getCustomerData($billingAddress),
                $customer
            );

        if ($address !== null) {
            $customer->setActiveBillingAddress($address);
            return $this->cartService->calculateCart($cart, $salesChannelContext);
        }

        return $cart;
    }

    /**
     * Map Google Pay address fields to Shopware field names
     *
     * @param array<mixed> $addressData
     * @return DataBag
     */
    protected function getCustomerData(array $addressData): DataBag
    {
        $mappings = [
            'name'              => 'first_name',
            'address1'          => 'street',
            'locality'          => 'city',
            'postalCode'        => 'postal_code',
            'countryCode'       => 'country_code',
        ];

        $data = [];
        foreach ($addressData as $key => $value) {
            if (isset($mappings[$key])) {
                $data[$mappings[$key]] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return new DataBag($data);
    }
}
