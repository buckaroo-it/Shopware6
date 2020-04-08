<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Helper;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidEntityIdException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidStateFieldException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;


class CheckoutHelper
{
    /** @var UrlGeneratorInterface $router */
    private $router;
    /** @var OrderTransactionStateHandler $orderTransactionStateHandler*/
    private $orderTransactionStateHandler;
    /** @var EntityRepository $transactionRepository */
    private $transactionRepository;
    /** @var EntityRepository $stateMachineRepository */
    private $stateMachineRepository;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var PluginService
     */
    private $pluginService;

    /**
     * CheckoutHelper constructor.
     * @param UrlGeneratorInterface $router
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param EntityRepository $transactionRepository
     * @param EntityRepository $stateMachineRepository
     * @param string $shopwareVersion
     * @param PluginService $pluginService
     */
    public function __construct(
        UrlGeneratorInterface $router,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $transactionRepository,
        EntityRepository $stateMachineRepository,
        string $shopwareVersion,
        PluginService $pluginService
    ) {
        $this->router = $router;
        $this->transactionRepository = $transactionRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->pluginService = $pluginService;
    }

    /**
     * @param string $address1
     * @param string $address2
     * @return array
     */
    public function parseAddress(string $address1, string $address2 = ''): array
    {
        $address1 = trim($address1);
        $address2 = trim($address2);
        $fullAddress = trim("{$address1} {$address2}");
        $fullAddress = preg_replace('/[[:blank:]]+/', ' ', $fullAddress);
        $matches = [];
        $pattern = '/(.+?)\s?([\d]+[\S]*)(\s?[A-z]*?)$/';
        preg_match($pattern, $fullAddress, $matches);
        $street = $matches[1] ?? '';
        $apartment = $matches[2] ?? '';
        $extension = $matches[3] ?? '';
        $street = trim($street);
        $apartment = trim($apartment . $extension);

        return [$street, $apartment];
    }

    /**
     * @param Request $request
     * @param CustomerEntity $customer
     * @return array
     */
    public function getCustomerData(Request $request, CustomerEntity $customer): array
    {
        [$billingStreet, $billingHouseNumber] = $this->parseAddress($customer->getDefaultBillingAddress()->getStreet());

        return [
            'locale' => $this->getTranslatedLocale($request->getLocale()),
            'ip_address' => $request->getClientIp(),
            'first_name' => $customer->getDefaultBillingAddress()->getFirstName(),
            'last_name' => $customer->getDefaultBillingAddress()->getLastName(),
            'address1' => $billingStreet,
            'house_number' => $billingHouseNumber,
            'zip_code' => $customer->getDefaultBillingAddress()->getZipcode(),
            'state' => $customer->getDefaultBillingAddress()->getCountryState(),
            'city' => $customer->getDefaultBillingAddress()->getCity(),
            'country' => $this->getCountryIso($customer->getDefaultBillingAddress()),
            'phone' => $customer->getDefaultBillingAddress()->getPhoneNumber(),
            'email' => $customer->getEmail(),
            'referrer' => $request->server->get('HTTP_REFERER'),
            'user_agent' => $request->headers->get('User-Agent')
        ];
    }

    /**
     * @param CustomerEntity $customer
     * @return array
     */
    public function getDeliveryData(CustomerEntity $customer): array
    {
        [
            $shippingStreet,
            $shippingHouseNumber
        ] = $this->parseAddress($customer->getDefaultShippingAddress()->getStreet());

        return [
            'first_name' => $customer->getDefaultShippingAddress()->getFirstName(),
            'last_name' => $customer->getDefaultShippingAddress()->getLastName(),
            'address1' => $shippingStreet,
            'house_number' => $shippingHouseNumber,
            'zip_code' => $customer->getDefaultShippingAddress()->getZipcode(),
            'state' => $customer->getDefaultShippingAddress()->getCountryState(),
            'city' => $customer->getDefaultShippingAddress()->getCity(),
            'country' => $this->getCountryIso($customer->getDefaultShippingAddress()),
            'phone' => $customer->getDefaultShippingAddress()->getPhoneNumber(),
            'email' => $customer->getEmail()
        ];
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @return array
     */
    public function getPaymentOptions(AsyncPaymentTransactionStruct $transaction): array
    {
        return [
            'notification_url' => $this->router->generate(
                'frontend.buckaroo.notification',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'ReturnURL' => $transaction->getReturnUrl(),
            'ReturnURLCancel' => sprintf('%s&cancel=1', $transaction->getReturnUrl()),
            'close_window' => false
        ];
        //ReturnURLError
        //ReturnURLReject
    }

    /**
     * @param OrderEntity $order
     * @return array
     */
    public function getShoppingCart(OrderEntity $order): array
    {
        $shoppingCart = [];
        foreach ($order->getLineItems() as $item) {
            $shoppingCart['items'][] = [
                'name' => $item->getLabel(),
                'description' => $item->getDescription(),
                'unit_price' => $this->getUnitPriceExclTax($item->getPrice()),
                'quantity' => $item->getQuantity(),
                'merchant_item_id' => $this->getMerchantItemId($item),
                'tax_table_selector' => (string) $this->getTaxRate($item->getPrice()),
            ];
        }

        // Add Shipping-cost
        $shoppingCart['items'][] = [
            'name' => 'Shipping',
            'description' => 'Shipping',
            'unit_price' => $this->getUnitPriceExclTax($order->getShippingCosts()),
            'quantity' => $order->getShippingCosts()->getQuantity(),
            'merchant_item_id' => 'bkr-shipping',
            'tax_table_selector' => (string) $this->getTaxRate($order->getShippingCosts()),
        ];

        return $shoppingCart;
    }


    /**
     * @param OrderEntity $order
     * @return array
     */
    public function getCheckoutOptions(OrderEntity $order): array
    {
        $checkoutOptions['tax_tables']['default'] = [
            'shipping_taxed' => true,
            'rate' => ''
        ];

        // Create array with unique tax rates from order_items
        foreach ($order->getLineItems() as $item) {
            $taxRates[] = $this->getTaxRate($item->getPrice());
        }
        // Add shippingTax to array with unique tax rates
        $taxRates[] = $this->getTaxRate($order->getShippingCosts());

        $uniqueTaxRates = array_unique($taxRates);

        // Add unique tax rates to CheckoutOptions
        foreach ($uniqueTaxRates as $taxRate) {
            $checkoutOptions['tax_tables']['alternate'][] = [
                'name' => (string) $taxRate,
                'standalone' => true,
                'rules' => [
                    [
                        'rate' => $taxRate / 100
                    ]
                ]
            ];
        }

        return $checkoutOptions;
    }


    /**
     * @param $locale
     * @return string
     */
    public static function getTranslatedLocale($locale = false): string
    {
        switch ($locale) {
            case 'nl':
                $translatedLocale = 'nl-NL';
                break;
            case 'de':
                $translatedLocale = 'de-DE';
                break;
            default:
                $translatedLocale = 'en-GB';
                break;
        }
        return $translatedLocale;
    }

    /**
     * @param CustomerAddressEntity $customerAddress
     * @return string|null
     */
    private function getCountryIso(CustomerAddressEntity $customerAddress): ?string
    {
        $country = $customerAddress->getCountry();
        if (!$country) {
            return null;
        }
        return $country->getIso();
    }

    /**
     * @param OrderLineItemEntity $item
     * @return mixed
     */
    private function getMerchantItemId(OrderLineItemEntity $item)
    {
        if ($item->getType() === 'promotion') {
            return $item->getPayload()['discountId'];
        }
        return $item->getPayload()['productNumber'];
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @return float
     */
    public function getTaxRate(CalculatedPrice $calculatedPrice) : float
    {
        $rates = [];
        foreach ($calculatedPrice->getCalculatedTaxes() as $tax) {
            $rates[] = $tax->getTaxRate();
        }
        // return highest taxRate
        return (float) max($rates);
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @return float
     */
    public function getUnitPriceExclTax(CalculatedPrice $calculatedPrice) : float
    {
        $unitPrice = $calculatedPrice->getUnitPrice();
        $taxRate = $this->getTaxRate($calculatedPrice);

        if ($unitPrice && $taxRate) {
            $unitPrice /= (1 + ($taxRate / 100));
        }
        return (float) $unitPrice;
    }

    /**
     * @param string $status
     * @param string $orderTransactionId
     * @param Context $context
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineInvalidEntityIdException
     * @throws StateMachineInvalidStateFieldException
     * @throws StateMachineNotFoundException
     * @throws StateMachineStateNotFoundException
     */
    public function transitionPaymentState(string $status, string $orderTransactionId, Context $context): void
    {
        $transitionAction = $this->getCorrectTransitionAction($status);

        if ($transitionAction === null) {
            return;
        }

        /**
         * Check if the state if from the current transaction is equal
         * to the transaction we want to transition to.
         */
        if ($this->isSameStateId($transitionAction, $orderTransactionId, $context)) {
            return;
        }

        try {
            $functionName = $this->convertToFunctionName($transitionAction);
            $this->orderTransactionStateHandler->$functionName($orderTransactionId, $context);
        } catch (IllegalTransitionException $exception) {
            if ($transitionAction !== StateMachineTransitionActions::ACTION_PAY) {
                return;
            }

            $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
            $this->transitionPaymentState($status, $orderTransactionId, $context);
        }
    }

    /**
     * @param string $status
     * @return string|null
     */
    public function getCorrectTransitionAction(string $status): ?string
    {
        switch ($status) {
            case 'completed':
                return StateMachineTransitionActions::ACTION_PAY;
                break;
            case 'declined':
            case 'cancelled':
            case 'void':
            case 'expired':
                return StateMachineTransitionActions::ACTION_CANCEL;
                break;
            case 'refunded':
                return StateMachineTransitionActions::ACTION_REFUND;
            case 'partial_refunded':
                return StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;
            case 'initialized':
                return StateMachineTransitionActions::ACTION_REOPEN;
        }
        return null;
    }

    /**
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        return $this->transactionRepository->search($criteria, $context)
            ->get($transactionId);
    }

    /**
     * @param string $actionName
     * @param string $orderTransactionId
     * @param Context $context
     * @return bool
     * @throws InconsistentCriteriaIdsException
     */
    public function isSameStateId(string $actionName, string $orderTransactionId, Context $context): bool
    {
        $transaction = $this->getTransaction($orderTransactionId, $context);
        $currentStateId = $transaction->getStateId();

        $actionStatusTransition = $this->getTransitionFromActionName($actionName, $context);
        $actionStatusTransitionId = $actionStatusTransition->getId();

        return $currentStateId === $actionStatusTransitionId;
    }

    /**
     * @param string $actionName
     * @param Context $context
     * @return StateMachineStateEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransitionFromActionName(string $actionName, Context $context): StateMachineStateEntity
    {
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $stateName));
        return $this->stateMachineRepository->search($criteria, $context)->first();
    }

    /**
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): string
    {
        switch ($actionName) {
            case StateMachineTransitionActions::ACTION_PAY:
                return OrderTransactionStates::STATE_PAID;
                break;
            case StateMachineTransitionActions::ACTION_CANCEL:
                return OrderTransactionStates::STATE_CANCELLED;
                break;
        }
        return OrderTransactionStates::STATE_OPEN;
    }

    /**
     * Convert from snake_case to CamelCase.
     *
     * @param string $string
     * @return string
     */
    private function convertToFunctionName(string $string): string
    {
        $string = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
        return lcfirst($string);
    }

    /**
     * @param CustomerEntity $customer
     * @return string|null
     */
    public function getGenderFromSalutation(CustomerEntity $customer): ?string
    {
        switch ($customer->getSalutation()->getSalutationKey()) {
            case 'mr':
                return 'male';
            case 'mrs':
                return 'female';
        }
        return null;
    }

    /**
     * @param Context $context
     * @return array
     * @throws \Shopware\Core\Framework\Plugin\Exception\PluginNotFoundException
     */
    public function getPluginMetadata(Context $context): array
    {
        return [
            'shop' => 'Shopware',
            'shop_version' => $this->shopwareVersion,
            'plugin_version' => $this->pluginService->getPluginByName('BuckarooPayment', $context)->getVersion(),
            'partner' => 'Buckaroo',
        ];
    }

    /**
     * @param  $amount
     * @return string
     */
    public function generateToken($amount):string
    {
        $amount = number_format($amount, 2);
        return md5(implode('|', [ $amount, microtime() ]));
    }

    public function generateSignature():string
    {
        return '';
    }

    public function getReturnUrl($route):string
    {
        return $this->router->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function saveTransactionData(string $orderTransactionId, Context $context, array $data): void
    {
        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $orderTransactionId
        );

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields = array_merge($customFields, $data);

        $this->updateTransactionCustomFields($orderTransactionId, $customFields);
    }

    public function updateTransactionCustomFields(string $orderTransactionId, array $customFields): void
    {
        $data = [
            'id'           => $orderTransactionId,
            'customFields' => $customFields,
        ];

        $this->transactionRepository->update([$data], Context::createDefaultContext());
    }

    public function getOrderTransactionById(Context $context, string $orderId): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $filter = new EqualsFilter('order_transaction.id', $orderId);
        $criteria->addFilter($filter);

        return $this->transactionRepository->search($criteria, $context)->first();
    }

    /**
     * Return the order repository.
     *
     * @return EntityRepository
     */
    public function getRepository()
    {
        return $this->transactionRepository;
    }

    /**
     * Return an order entity, enriched with associations.
     *
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity|null
     */
    public function getOrder(string $orderId, Context $context) : ?OrderEntity
    {
        $order = null;

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));
            $criteria->addAssociation('currency');
            $criteria->addAssociation('addresses');
            $criteria->addAssociation('language');
            $criteria->addAssociation('language.locale');
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('deliveries');
            $criteria->addAssociation('deliveries.shippingOrderAddress');

            /** @var OrderEntity $order */
            $order = $this->transactionRepository->search($criteria, $context)->first();
        } catch (\Exception $e) {
            // $this->logger->error($e->getMessage(), [$e]);
        }

        return $order;
    }

    /**
     * Return an array of order lines.
     *
     * @param OrderEntity $order
     * @return array
     */
    public function getOrderLinesArray(OrderEntity $order)
    {
        // Variables
        $lines = [];
        $lineItems = $order->getLineItems();

        if ($lineItems === null || $lineItems->count() === 0) {
            return $lines;
        }

        // Get currency code
        $currency = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        foreach ($lineItems as $item) {
            // Get tax
            $itemTax = null;

            if ($item->getPrice() !== null &&
                $item->getPrice()->getCalculatedTaxes() !== null) {
                $itemTax = $this->getLineItemTax($item->getPrice()->getCalculatedTaxes());
            }

            // Get VAT rate and amount
            $vatRate = $itemTax !== null ? $itemTax->getTaxRate() : 0.0;
            $vatAmount = $itemTax !== null ? $itemTax->getTax() : null;

            if ($vatAmount === null && $vatRate > 0) {
                $vatAmount = $item->getTotalPrice() * ($vatRate / ($vatRate + 100));
            }

            // Build the order lines array
            $lines[] = [
                // 'type' =>  $this->getLineItemType($item),
                'type' =>  'Article',
                'name' => $item->getLabel(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $this->getPriceArray($currencyCode, $item->getUnitPrice()),
                'totalAmount' => $this->getPriceArray($currencyCode, $item->getTotalPrice()),
                'vatRate' => number_format($vatRate, 2, '.', ''),
                'vatAmount' => $this->getPriceArray($currencyCode, $vatAmount),
                'sku' => $item->getId(),
                'imageUrl' => null,
                'productUrl' => null,
            ];
        }

        $lines[] = $this->getShippingItemArray($order);

        return $lines;
    }

    /**
     * Return an array of shipping data.
     *
     * @param OrderEntity $order
     * @return array
     */
    public function getShippingItemArray(OrderEntity $order) : array
    {
        // Variables
        $line = [];
        $shipping = $order->getShippingCosts();

        if ($shipping === null) {
            return $line;
        }

        // Get currency code
        $currency = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        // Get shipping tax
        $shippingTax = null;

        if ($shipping->getCalculatedTaxes() !== null) {
            $shippingTax = $this->getLineItemTax($shipping->getCalculatedTaxes());
        }

        // Get VAT rate and amount
        $vatRate = $shippingTax !== null ? $shippingTax->getTaxRate() : 0.0;
        $vatAmount = $vatAmount = $shippingTax !== null ? $shippingTax->getTax() : null;

        if ($vatAmount === null && $vatRate > 0) {
            $vatAmount = $shipping->getTotalPrice() * ($vatRate / ($vatRate + 100));
        }

        // Build the order line array
        $line = [
            // 'type' =>  OrderLineType::TYPE_SHIPPING_FEE,
            'type' =>  'Shipping',
            'name' => 'Shipping',
            'quantity' => $shipping->getQuantity(),
            'unitPrice' => $this->getPriceArray($currencyCode, $shipping->getUnitPrice()),
            'totalAmount' => $this->getPriceArray($currencyCode, $shipping->getTotalPrice()),
            'vatRate' => number_format($vatRate, 2, '.', ''),
            'vatAmount' => $this->getPriceArray($currencyCode, $vatAmount),
            'sku' => 'Shipping',
            'imageUrl' => null,
            'productUrl' => null,
        ];

        return $line;
    }

    /**
     * Return an array of price data; currency and value.
     * @param string $currency
     * @param float $price
     * @param int $decimals
     * @return array
     */
    public function getPriceArray(string $currency, float $price, int $decimals = 2) : array
    {
        return [
            'currency' => $currency,
            'value' => number_format($price, $decimals, '.', '')
        ];
    }

    /**
     * Return the type of the line item.
     *
     * @param OrderLineItemEntity $item
     * @return string|null
     */
/*    public function getLineItemType(OrderLineItemEntity $item) : ?string
    {
        if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return OrderLineType::TYPE_PHYSICAL;
        }

        if ($item->getType() === LineItem::CREDIT_LINE_ITEM_TYPE) {
            return OrderLineType::TYPE_STORE_CREDIT;
        }

        if ($item->getType() === PromotionProcessor::LINE_ITEM_TYPE ||
            $item->getTotalPrice() < 0) {
            return OrderLineType::TYPE_DISCOUNT;
        }

        return OrderLineType::TYPE_DIGITAL;
    }
*/
    /**
     * Return a calculated tax struct for a line item.
     *
     * @param CalculatedTaxCollection $taxCollection
     * @return CalculatedTax|null
     */
    public function getLineItemTax(CalculatedTaxCollection $taxCollection)
    {
        $tax = null;

        if ($taxCollection->count() > 0) {
            /** @var CalculatedTax $tax */
            $tax = $taxCollection->first();
        }

        return $tax;
    }

    /**
     * Return a customer entity with address associations.
     *
     * @param string $customerId
     * @param Context $context
     * @return CustomerEntity|null
     */
    public function getCustomer(string $customerId, Context $context) : ?CustomerEntity
    {
        $customer = null;

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $customerId));
            $criteria->addAssociation('activeShippingAddress');
            $criteria->addAssociation('activeBillingAddress');
            $criteria->addAssociation('defaultShippingAddress');
            $criteria->addAssociation('defaultBillingAddress');

            /** @var CustomerEntity $customer */
            $customer = $this->transactionRepository->search($criteria, $context)->first();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), [$e]);
        }

        return $customer;
    }

    public function getAddressArray($order, $additional, &$latestKey, $salesChannelContext, $dataBag)
    {
        if ($order->getOrderCustomer() !== null) {
            $customer = $this->getCustomer(
                $order->getOrderCustomer()->getCustomerId(),
                $salesChannelContext->getContext()
            );
        }

        if ($customer === null) {
            $customer = $salesChannelContext->getCustomer();
        }

        $address = $customer->getDefaultBillingAddress();

        if ($address === null) {
            return $additional;
        }

        $streetFormat   = $this->formatStreet($address->getStreet());

/*      $billingAddress = $order->getBillingAddress();
        $birthDayStamp = str_replace('/', '-', $payment->getAdditionalInformation('customer_DoB'));
        $identificationNumber = $payment->getAdditionalInformation('customer_identificationNumber');
        $telephone = $payment->getAdditionalInformation('customer_telephone');
        $telephone = (empty($telephone) ? $billingAddress->getTelephone() : $telephone);

        if ($payment->getAdditionalInformation('customer_gender') === '1') {
            $gender = 'Mr';
        }*/

        // $birthDayStamp = '01-01-1990';
        // $address->setPhoneNumber('0201234567');
        // $gender = 'Mrs';
        $birthDayStamp = $dataBag->get('buckaroo_afterpay_DoB');
        $address->setPhoneNumber($dataBag->get('buckaroo_afterpay_phone'));
        $gender = $dataBag->get('buckaroo_afterpay_genderSelect') ==2 ? 'Mrs' : 'Mr';

        $category = 'Person';
        $billingData = [
            [
                '_'    => $category,
                'Name' => 'Category',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getFirstName(),
                'Name' => 'FirstName',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getLastName(),
                'Name' => 'LastName',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getStreet(),
                'Name' => 'Street',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getZipCode(),
                'Name' => 'PostalCode',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getCity(),
                'Name' => 'City',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getCountry() !== null ? $address->getCountry()->getIso() : 'NL',
                'Name' => 'Country',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getPhoneNumber(),
                'Name' => 'MobilePhone',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $address->getPhoneNumber(),
                'Name' => 'Phone',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $customer->getEmail(),
                'Name' => 'Email',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
        ];

        if (!empty($streetFormat['house_number'])) {
            $billingData[] = [
                '_'    => $streetFormat['house_number'],
                'Name' => 'StreetNumber',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($streetFormat['number_addition'])) {
            $billingData[] = [
                '_'    => $streetFormat['number_addition'],
                'Name' => 'StreetNumberAdditional',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if ($address->getCountry()->getIso() == 'FI') {
            $billingData[] = [
                '_'    => $identificationNumber,
                'Name' => 'IdentificationNumber',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if ($address->getCountry()->getIso() == 'NL' || $address->getCountry()->getIso() == 'BE') {
            $billingData[] = [
                '_'    => $gender,
                'Name' => 'Salutation',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];

            $billingData[] = [
                '_'    => $birthDayStamp,
                'Name' => 'BirthDate',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        $latestKey++;

        return array_merge($additional,[$billingData]);

    }

    /**
     * @param $street
     *
     * @return array
     */
    public function formatStreet($street)
    {
        // $street = implode(' ', $street);
        $format = [
            'house_number'    => '',
            'number_addition' => '',
            'street'          => $street
        ];

        if (preg_match('#^(.*?)([0-9]+)(.*)#s', $street, $matches)) {
            // Check if the number is at the beginning of streetname
            if ('' == $matches[1]) {
                $format['house_number'] = trim($matches[2]);
                $format['street']       = trim($matches[3]);
            } else {
                $format['street']          = trim($matches[1]);
                $format['house_number']    = trim($matches[2]);
                $format['number_addition'] = trim($matches[3]);
            }
        }
        return $format;
    }

    public function getArticleData($order, $additional, &$latestKey){
        $lines = $this->getOrderLinesArray($order);

        foreach ($lines as $key => $item) {
            $additional[] = [
                [
                    '_'       => $item['name'],
                    'Name'    => 'Description',
                    'GroupID' => $latestKey,
                    'Group' => 'Article',
                ],
                [
                    '_'       => $item['sku'],
                    'Name'    => 'Identifier',
                    'Group' => 'Article',
                    'GroupID' => $latestKey,
                ],
                [
                    '_'       => $item['quantity'],
                    'Name'    => 'Quantity',
                    'GroupID' => $latestKey,
                    'Group' => 'Article',
                ],
                [
                    '_'       => $item['unitPrice']['value'],
                    'Name'    => 'GrossUnitPrice',
                    'GroupID' => $latestKey,
                    'Group' => 'Article',
                ],
                [
                    '_'       => $item['vatAmount']['value'],
                    'Name'    => 'VatPercentage',
                    'GroupID' => $latestKey,
                    'Group' => 'Article',
                ]
            ];
            $latestKey++;
        }

        return $additional;
    }

}
