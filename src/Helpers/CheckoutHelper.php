<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

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

use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Buckaroo\Shopware6\Service\SettingsService;

use Buckaroo\Shopware6\Helpers\Helper;
use Buckaroo\Shopware6\Helpers\UrlHelper;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Exception\InvalidQuantityException;
use Shopware\Core\Checkout\Cart\Exception\LineItemNotFoundException;
use Shopware\Core\Checkout\Cart\Exception\LineItemNotStackableException;
use Shopware\Core\Checkout\Cart\Exception\MixedLineItemTypeException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;

use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\Uuid\Uuid;

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
    /** @var Helper */
    private $helper;
    /** @var CartService */
    private $cartService;
    /** * @var string */
    private $shopwareVersion;
    /** * @var PluginService */
    private $pluginService;
    /** @var SettingsService $settingsService */
    private $settingsService;
    /** @var EntityRepositoryInterface $orderRepository */
    private $orderRepository;

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
        PluginService $pluginService,
        SettingsService $settingsService,
        Helper $helper,
        CartService $cartService,
        EntityRepositoryInterface $orderRepository
    ) {
        $this->router = $router;
        $this->transactionRepository = $transactionRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->pluginService = $pluginService;
        $this->settingsService = $settingsService;
        $this->helper = $helper;
        $this->cartService = $cartService;
        $this->orderRepository = $orderRepository;
    }

    public function getSetting($name)
    {
        return $this->settingsService->getSetting($name);
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
         * Check if the current transaction state is equal
         */
        if ($this->isSameState($transitionAction, $orderTransactionId, $context)) {
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
    public function getOrderTransaction(string $transactionId, Context $context): OrderTransactionEntity
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
    public function isSameState(string $actionName, string $orderTransactionId, Context $context): bool
    {
        $transaction = $this->getOrderTransaction($orderTransactionId, $context);
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

            $type = 'Article';
            $unitPrice = $this->getPriceArray($currencyCode, $item->getUnitPrice());
            $vatAmount = $this->getPriceArray($currencyCode, $vatAmount);
            if($unitPrice['value']<0){
                $type = 'Discount';
                $vatAmount['value'] = 0;
                $vatRate = 0.0;
            }

            // Build the order lines array
            $lines[] = [
                'type' =>  $type,
                'name' => $item->getLabel(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $unitPrice,
                'totalAmount' => $this->getPriceArray($currencyCode, $item->getTotalPrice()),
                'vatRate' => number_format($vatRate, 2, '.', ''),
                'vatAmount' => $vatAmount,
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
        $birthDayStamp = $dataBag->get('buckaroo_afterpay_DoB');
        $address->setPhoneNumber($dataBag->get('buckaroo_afterpay_phone'));
        // $gender = $dataBag->get('buckaroo_afterpay_genderSelect') ==2 ? 'Mrs' : 'Mr';
        $salutation = $customer->getSalutation()->getSalutationKey();

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
                '_'    => $salutation,
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
                $format['number_addition'] = trim(str_replace(',','',$matches[3]));
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
                    '_'       => $item['vatRate'],
                    'Name'    => 'VatPercentage',
                    'GroupID' => $latestKey,
                    'Group' => 'Article',
                ]
            ];
            $latestKey++;
        }

        return $additional;
    }


    public function getRefundArticleData($amount){

        $additional[] = [
                        [
                '_'       => 'Return',
                'Name'    => 'RefundType',
                'GroupID' => 1,
                'Group' => 'Article',
            ],[
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group' => 'Article',
            ], [
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group' => 'Article',
            ],
            [
                '_'       => '1',
                'Name'    => 'Identifier',
                'Group' => 'Article',
                'GroupID' => 1,
            ],
            [
                '_'       => '1',
                'Name'    => 'Quantity',
                'GroupID' => 1,
                'Group' => 'Article',
            ],
            [
                '_'       => $amount,
                'Name'    => 'GrossUnitPrice',
                'GroupID' => 1,
                'Group' => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'VatPercentage',
                'GroupID' => 1,
                'Group' => 'Article',
            ]
        ];

        return $additional;
    }

    public function getTransferData($order, $additional, $salesChannelContext, $dataBag)
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

        $now = new \DateTime();
        $now->modify('+' . ($this->getSetting('transferDueDate') > 0 ? $this->getSetting('transferDueDate') : 7 ) . ' day');
        $sendEmail = $this->getSetting('transferSendEmail') ? 'true' : 'false';

        $services = [
                [
                    '_'    => $address->getFirstName(),
                    'Name' => 'CustomerFirstName',
                ],
                [
                    '_'    => $address->getLastName(),
                    'Name' => 'CustomerLastName',
                ],
                [
                    '_'    => $address->getCountry()->getIso(),
                    'Name' => 'CustomerCountry',
                ],
                [
                    '_'    => $customer->getEmail(),
                    'Name' => 'CustomerEmail',
                ],
                [
                    '_'    => $now->format('Y-m-d'),
                    'Name' => 'DateDue'
                ],
                [
                    '_'    => $sendEmail,
                    'Name' => 'SendMail'
                ]
        ];

        return array_merge($additional,[$services]);
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
     * Get the base url
     * When the environment is set live, but the payment is set as test, the test url will be used
     *
     * @return string Base-url
     */
    public function getBaseUrl($method = ''):string
    {
        return $this->helper->getEnvironment($method) == 'live' ? UrlHelper::LIVE : UrlHelper::TEST;
    }

    /**
     * @return string Full transaction url
     */
    public function getTransactionUrl($method = ''):string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/Transaction', '/');
    }


    public function addLineItems($lineItems, SalesChannelContext $salesChannelContext)
    {
        $count = 0;
        try {
            $cart = new Cart('recalculation', Uuid::randomHex());
            foreach ($lineItems as $lineItemDatas) {
                foreach ($lineItemDatas as $referencedId=>$lineItemData) {

                    $lineItem = new LineItem(
                        $lineItemData['id'],
                        $lineItemData['type'],
                        $referencedId,
                        $lineItemData['quantity']
                    );

                    $lineItem->setStackable(true);
                    $lineItem->setRemovable(true);

                    $count += $lineItem->getQuantity();

                    $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
                }
            }
        } catch (ProductNotFoundException $exception) {
        }
    }

    public function getCustomFields($order, $context)
    {
        $transaction = $order->getTransactions()->first();

        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $transaction->getId()
        );
        $customField = $orderTransaction->getCustomFields() ?? [];

        $method_path = str_replace('Handlers', 'PaymentMethods', str_replace('PaymentHandler', '', $transaction->getPaymentMethod()->getHandlerIdentifier()));
        $paymentMethod = new $method_path;
        $customField['refund'] = $paymentMethod->canRefund() ? 1 : 0;
        $customField['serviceName'] = $paymentMethod->getBuckarooKey();
        $customField['version'] = $paymentMethod->getVersion();

        return $customField;
    }

    /**
     * Check if this event is triggered using a Buckaroo Payment Method
     *
     * @param OrderEntity $order
     * @return bool
     */
    private function isBuckarooPaymentMethod(OrderEntity $order): bool
    {
        $transaction = $order->getTransactions()->first();

        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        $plugin = $transaction->getPaymentMethod()->getPlugin();

        return end(explode('\\', $plugin->getBaseClass())) === end(explode('\\', BuckarooPayment::class));

        return $plugin->getBaseClass() === BuckarooPayment::class;
    }

    public function getOrderById($orderId, $context): ?OrderEntity
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');
        
        return $this->orderRepository->search($orderCriteria, $context)->first();
    }

    public function refundTransaction($order, $context, $state, $amount = false)
    {
        if (!$this->isBuckarooPaymentMethod($order)) {
            return;
        }

        $customFields = $this->getCustomFields($order, $context);

        if($customFields['refund']==0){
            return false;
        }

        if($customFields['refunded']==1){
            return false;
        }

        $request = new TransactionRequest;
        $request->setServiceAction('Refund');
        $request->setDescription('Refund for order #' . $order->getOrderNumber());
        $request->setServiceName($customFields['serviceName']);
        $request->setAmountCredit($amount ? $amount : $order->getAmountTotal());
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency('EUR');
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);
        $request->setServiceVersion($customFields['version']);

        if($customFields['serviceName']=='afterpay'){
            $additional = $this->getRefundArticleData($amount);
            foreach ($additional as $key2 => $item) {
                foreach ($item as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        if($customFields['serviceName']=='sepadirectdebit'){
            $request->setChannelHeader('Backoffice');
        }

        $url = $this->getTransactionUrl($customFields['serviceName']);
        $bkrClient = $this->helper->initializeBkr();
        $response = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');

        if($response->isSuccess()){
            $transaction = $order->getTransactions()->first();
            $status = ($amount < $order->getAmountTotal()) ? 'partial_refunded' : 'refunded';
            $this->transitionPaymentState($status, $transaction->getId(), $context);
            $this->saveTransactionData($transaction->getId(), $context, [$status => 1]);
            return new JsonResponse(['status' => true]);
        }

         return new JsonResponse(
                [
                    'status'  => false,
                    'message' => $response->getSubCodeMessageFull() ?? $response->getSomeError(),
                    'code'    => $response->getStatusCode(),
                ],
                Response::HTTP_BAD_REQUEST
            );
    }

    /**
     * Generate/calculate the signature with the buckaroo config value and check if thats equal to the signature
     * received from the push
     *
     * @return bool
     */
    public function validateSignature()
    {
        $request = $this->helper->getGlobals();
        $postData = $_POST;

        if (!isset($postData['brq_signature'])) {
            return false;
        }

        $signature = $this->calculateSignature($postData);

        if ($signature !== $postData['brq_signature']) {
            return false;
        }

        return true;
    }

    /**
     * Determines the signature using array sorting and the SHA1 hash algorithm
     *
     * @param $postData
     *
     * @return string
     */
    protected function calculateSignature($postData)
    {
        $copyData = $postData;
        unset($copyData['brq_signature']);

        $sortableArray = $this->buckarooArraySort($copyData);

        $signatureString = '';

        foreach ($sortableArray as $brq_key => $value) {
            $value = $this->decodePushValue($brq_key, $value);

            $signatureString .= $brq_key. '=' . $value;
        }

        $signatureString .= $this->helper->getSettingsValue('secretKey');

        $signature = SHA1($signatureString);

        return $signature;
    }

    /**
     * @param string $brq_key
     * @param string $brq_value
     *
     * @return string
     */
    private function decodePushValue($brq_key, $brq_value)
    {
        switch ($brq_key) {
            case 'brq_SERVICE_payconiq_PayconiqAndroidUrl':
            case 'brq_SERVICE_payconiq_PayconiqIosUrl':
            case 'brq_SERVICE_payconiq_PayconiqUrl':
            case 'brq_SERVICE_payconiq_QrUrl':
            case 'brq_SERVICE_masterpass_CustomerPhoneNumber':
            case 'brq_SERVICE_masterpass_ShippingRecipientPhoneNumber':
            case 'brq_InvoiceDate':
            case 'brq_DueDate':
            case 'brq_PreviousStepDateTime':
            case 'brq_EventDateTime':
                $decodedValue = $brq_value;
                break;
            default:
                $decodedValue = urldecode($brq_value);
        }

        return $decodedValue;
    }

    /**
     * Sort the array so that the signature can be calculated identical to the way buckaroo does.
     *
     * @param $arrayToUse
     *
     * @return array $sortableArray
     */
    protected function buckarooArraySort($arrayToUse)
    {
        $arrayToSort   = [];
        $originalArray = [];

        foreach ($arrayToUse as $key => $value) {
            $arrayToSort[strtolower($key)]   = $value;
            $originalArray[strtolower($key)] = $key;
        }

        ksort($arrayToSort);

        $sortableArray = [];

        foreach ($arrayToSort as $key => $value) {
            $key = $originalArray[$key];
            $sortableArray[$key] = $value;
        }

        return $sortableArray;
    }

}
