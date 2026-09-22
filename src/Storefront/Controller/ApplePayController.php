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
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
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
    #[Route(path: '/buckaroo/apple/cart/get', name: 'frontend.action.buckaroo.appleGetCart', options: ['seo' => false], methods: ['POST'], defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
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
            // error level: debug is not written in production, which hides the
            // real cause behind the generic "unknown error" JSON response.
            $this->logger->error('[ApplePay] request failed: ' . (string)$th);
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
    #[Route("/buckaroo/apple/cart/update", name: "frontend.action.buckaroo.appleUpdateCart", options: ["seo" => false], methods: ["POST"], defaults: ["XmlHttpRequest" => true, "_routeScope" => ["storefront"]])]
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
                // shippingContact is a nested JSON object. On Symfony 7 (Shopware 6.7)
                // InputBag::get() throws BadRequestException for non-scalar values,
                // so the array variant all($key) must be used here.
                $this->loginCustomer(
                    $this->getCustomerData($request->request->all('shippingContact')),
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
            // error level: debug is not written in production, which hides the
            // real cause behind the generic "unknown error" JSON response.
            $this->logger->error('[ApplePay] request failed: ' . (string)$th);
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
    #[Route("/buckaroo/apple/order/create", name: "frontend.action.buckaroo.appleCreateOrder", options: ["seo" => false], methods: ["POST"], defaults: ["XmlHttpRequest" => true, "_routeScope" => ["storefront"]])]
    public function createAppleOrder(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

        try {
            // Inside the try: a failure here must return JSON, not a 500 page —
            // an HTML 500 leaves the Apple Pay sheet waiting for completePayment()
            // until it times out (~30s) and fails on the device.
            $this->overrideChannelPaymentMethod($salesChannelContext, 'ApplePayPaymentHandler');

            $redirectPath = $this->placeOrder(
                $this->createOrder($salesChannelContext, $request),
                $salesChannelContext,
                new RequestDataBag([
                    "applePayInfo" => $request->request->get('payment')
                ])
            );

            $redirect = $this->getFinishPage($redirectPath);

            if ($redirect !== null) {
                // Order placed and payment initiated successfully: delete the cart.
                // The custom order path bypasses Shopware's CartOrderRoute, which is
                // where the cart is normally removed after checkout — without this
                // the paid cart stays in the storefront.
                $this->deleteCartAfterOrder($request, $salesChannelContext);
            }

            return $this->response([
                "redirect" => $redirect
            ]);
        } catch (\Throwable $th) {
            // error level: debug is not written in production, which hides the
            // real cause behind the generic "unknown error" JSON response.
            $this->logger->error('[ApplePay] request failed: ' . (string)$th);
            return $this->response(
                ["message" => $this->trans("buckaroo.button_payment.unknown_error")],
                true
            );
        }
    }

    /**
     * Delete the cart that was just converted into an order. Deletes by the
     * same token the order was created from (standard checkout and cart-page
     * express use the session cart; product-page express uses its own
     * temporary cart, so the shopper's session cart is left untouched there).
     * Cleanup must never fail a successful payment.
     */
    private function deleteCartAfterOrder(Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            $cartToken = $request->request->get('cartToken');
            if (!is_string($cartToken) || $cartToken === '') {
                $cartToken = $salesChannelContext->getToken();
            }

            $this->cartService->deleteCartByToken($cartToken, $salesChannelContext);
        } catch (\Throwable $th) {
            $this->logger->warning('[ApplePay] could not delete cart after order: ' . $th->getMessage());
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
            // Express flow: the guest login performed during cart/update runs under a
            // restored context token that never reaches the browser, so this request
            // arrives with the original anonymous token and no customer. Create/log in
            // the guest here from the full (post-authorisation) Apple Pay contact.
            $this->ensureCustomer($request, $salesChannelContext);

            $paymentData = $request->request->get('payment');
            if (is_string($paymentData)) {
                $paymentData = json_decode($paymentData, true);
            }

            // The guest created during cart/update only had the redacted pre-auth
            // contact (zip/city/country) — replace the placeholder name/email and
            // shipping address with the authorised contact so the order does not
            // show "Unknown Customer - Buckaroo Payments".
            $this->updateGuestCustomerIdentity($paymentData, $salesChannelContext);

            $updatedCart = $this->updateCartShippingAddress($cart, $salesChannelContext, $paymentData);
            if ($updatedCart !== null) {
                $cart = $updatedCart;
            }

            $updatedCart = $this->updateCartBillingAddress(
                $cart,
                $salesChannelContext,
                $paymentData
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
     * Make sure the sales channel context has a customer for express orders.
     * Uses the authorised Apple Pay contact (shipping preferred — it carries the
     * full postal address; billing as fallback) to create and log in a guest.
     */
    private function ensureCustomer(Request $request, SalesChannelContext $salesChannelContext): void
    {
        if ($salesChannelContext->getCustomer() !== null) {
            return;
        }

        $paymentData = $request->request->get('payment');
        if (is_string($paymentData)) {
            $paymentData = json_decode($paymentData, true);
        }

        $contact = null;
        if (is_array($paymentData)) {
            $contact = $paymentData['shippingContact'] ?? $paymentData['billingContact'] ?? null;

            // The e-mail address is usually only present on the shipping contact;
            // carry it over so the guest gets the real address either way.
            if (is_array($contact) &&
                empty($contact['emailAddress']) &&
                !empty($paymentData['shippingContact']['emailAddress'])
            ) {
                $contact['emailAddress'] = $paymentData['shippingContact']['emailAddress'];
            }
        }

        if (!is_array($contact) || $contact === []) {
            throw new \InvalidArgumentException('Cannot create guest customer: no Apple Pay contact available');
        }

        $this->loginCustomer(
            $this->getCustomerData($contact),
            $salesChannelContext
        );
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
     * Update the guest's placeholder identity (name/email) with the authorised
     * Apple Pay contact. Only guests are touched — a logged-in account is never
     * overwritten with wallet data.
     *
     * @param mixed $paymentData
     */
    private function updateGuestCustomerIdentity($paymentData, SalesChannelContext $salesChannelContext): void
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null || $customer->getGuest() !== true || !is_array($paymentData)) {
            return;
        }

        $contact = $paymentData['shippingContact'] ?? $paymentData['billingContact'] ?? null;
        if (!is_array($contact) || $contact === []) {
            return;
        }

        if (empty($contact['emailAddress']) && !empty($paymentData['shippingContact']['emailAddress'])) {
            $contact['emailAddress'] = $paymentData['shippingContact']['emailAddress'];
        }

        $this->customerService
            ->setSaleChannelContext($salesChannelContext)
            ->updateCustomerIdentity($customer, $this->getCustomerData($contact));
    }

    /**
     * Create the shipping address from the authorised (full) Apple Pay shipping
     * contact, activate it and move the context's shipping location onto it, so
     * the order delivery address is the real one instead of the redacted
     * pre-authorisation placeholder.
     *
     * @param mixed $paymentData
     */
    protected function updateCartShippingAddress(
        Cart $cart,
        SalesChannelContext $salesChannelContext,
        $paymentData
    ): ?Cart {
        if (is_string($paymentData)) {
            $paymentData = json_decode($paymentData, true);
        }

        if (!is_array($paymentData) ||
            !isset($paymentData['shippingContact']) ||
            !is_array($paymentData['shippingContact'])
        ) {
            return null;
        }

        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            throw new \InvalidArgumentException('Customer cannot be null');
        }

        $address = $this->customerService
            ->setSaleChannelContext($salesChannelContext)
            ->createAddress(
                $this->getCustomerData($paymentData['shippingContact']),
                $customer
            );

        if ($address !== null) {
            $customer->setActiveShippingAddress($address);
            $salesChannelContext->assign([
                'shippingLocation' => ShippingLocation::createFromAddress($address)
            ]);
            return $this->cartService->calculateCart($cart, $salesChannelContext);
        }

        return $cart;
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
                'label' => $shippingMethod->getName() ?? '',
                // Apple Pay expects string amounts ("4.99"); null detail renders as "null" on the sheet
                'amount' => $this->formatNumber($amount),
                'identifier' => $shippingMethod->getId(),
                'detail' => $shippingMethod->getDescription() ?? ''
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

        /** @var ShippingMethodEntity|null */
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
            'countryCode' => 'country_code',
            // CustomerService reads 'email' — map Apple's key so the guest gets
            // the shopper's real address instead of the no-reply fallback.
            'emailAddress' => 'email'
        ];

        $data = [];
        foreach ($contactData as $key => $value) {
            // Apple sends addressLines as an array of street lines — the DAL
            // expects a plain string for street, so flatten it here.
            if ($key === 'addressLines' && is_array($value)) {
                $value = trim(implode(' ', array_filter($value, 'is_string')));
            }

            // Redacted (pre-authorisation) contacts contain empty strings/arrays
            // for the hidden fields; skip them so downstream defaults apply
            // instead of writing empty/array values into the DAL.
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (isset($mappings[$key])) {
                $data[$mappings[$key]] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return new DataBag($data);
    }
}
