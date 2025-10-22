<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

abstract class AbstractPaymentController extends StorefrontController
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
    protected SettingsService $settingsService;

    public function __construct(
        CartService $cartService,
        CustomerService $customerService,
        OrderService $orderService,
        SettingsService $settingsService,
        SalesChannelRepository $paymentMethodRepository
    ) {
        $this->cartService = $cartService;
        $this->customerService = $customerService;
        $this->orderService = $orderService;
        $this->settingsService = $settingsService;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    protected function formatNumber(float $number): string
    {
        return number_format($number, 2);
    }
    /**
     * Return json response with data
     *
     * @param array<mixed> $data
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
     * @param DataBag $customerData
     * @param SalesChannelContext $salesChannelContext
     *
     * @return CustomerEntity
     */
    protected function loginCustomer(DataBag $customerData, SalesChannelContext $salesChannelContext): CustomerEntity
    {
        return $this->customerService
            ->setSaleChannelContext($salesChannelContext)
            ->get($customerData);
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

        $cart = $this->getCartByToken(
            $salesChannelContext->getToken(),
            $salesChannelContext
        );

        if ($cart === null) {
            throw new \Exception("Cannot retrieve cart by token", 1);
        }

        return $cart;
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
        return new DataBag((array)$request->request->all('form'));
    }

    /**
     * Get product data from from data
     *
     * @param DataBag $formData
     *
     * @return array<mixed>
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

        // Check if any required keys are missing using array_diff for clarity
        $missingKeys = array_diff($keysRequired, array_keys($productData));
        if (!empty($missingKeys)) {
            throw new InvalidParameterException(
                "Missing required product parameters: " . implode(', ', $missingKeys),
                1
            );
        }

        $quantity = $productData['quantity'];
        if (!is_scalar($quantity)) {
            throw new InvalidParameterException("Invalid quantity", 1);
        }

        return [
            "id" => $productData['id'],
            "quantity" => (int)$quantity,
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
     * Get cart by token
     *
     * @param mixed $token
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart|null
     */
    protected function getCartByToken($token, SalesChannelContext $salesChannelContext)
    {
        if (!is_string($token)) {
            throw new \InvalidArgumentException('Cart token must be a string');
        }
        return $this->cartService
            ->setSaleChannelContext($salesChannelContext)
            ->load($token);
    }


    /**
     * Place order and do payment
     *
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $data
     *
     * @return string|null
     */
    protected function placeOrder(
        OrderEntity $orderEntity,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $data
    ) {
        return $this->orderService
            ->setSaleChannelContext($salesChannelContext)
            ->place($orderEntity, $data);
    }

    /**
     * Get absolute url to the finish page after payment
     *
     * @param mixed $redirectPath
     *
     * @return string|null
     */
    protected function getFinishPage($redirectPath): ?string
    {
        if (is_string($redirectPath)) {
            return $this->generateUrl('frontend.home.page', [], UrlGeneratorInterface::ABSOLUTE_URL) .
                ltrim($redirectPath, "/");
        }
        return null;
    }
    /**
     * Get payment fee
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $feeName
     *
     * @return float
     */
    public function getFee(SalesChannelContext $salesChannelContext, string $feeName): float
    {
        $fee = $this->settingsService->getSetting($feeName, $salesChannelContext->getSalesChannelId());
        if (is_scalar($fee)) {
            return round((float)str_replace(',', '.', (string)$fee), 2);
        }
        return 0;
    }

    /**
     * Set paypal as payment method
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $handler
     *
     * @return void
     */
    protected function overrideChannelPaymentMethod(SalesChannelContext $salesChannelContext, string $handler): void
    {
        $paymentMethod = $this->getValidPaymentMethod($salesChannelContext, $handler);

        if ($paymentMethod === null) {
            throw new \Exception("Cannot set payment method", 1);
        }
        $salesChannelContext->assign([
            'paymentMethod' => $paymentMethod
        ]);
    }

    /**
     * Get paypal payment method
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $handler
     *
     * @return \Shopware\Core\Checkout\Payment\PaymentMethodEntity|null
     */
    public function getValidPaymentMethod(SalesChannelContext $salesChannelContext, string $handler)
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('handlerIdentifier', "Buckaroo\Shopware6\Handlers\\" . $handler));

        /** @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity|null */
        return $this->paymentMethodRepository->search(
            $criteria,
            $salesChannelContext
        )
            ->first();
    }
}
