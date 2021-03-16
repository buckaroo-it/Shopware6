<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\BuckarooPayments;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntity;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidEntityIdException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidStateFieldException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Defaults;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\ResultStatement;
use Shopware\Core\Checkout\Document\DocumentConfigurationFactory;
use Shopware\Core\Checkout\Document\DocumentService;
use Shopware\Core\Checkout\Document\DocumentConfiguration;
use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Document\FileGenerator\FileTypes;
use Exception;
use Symfony\Component\HttpFoundation\ParameterBag;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\MailTemplate\Service\MailService;
use Shopware\Core\Checkout\Document\Exception\InvalidDocumentException;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use RuntimeException;
use Shopware\Core\Checkout\Order\OrderDefinition;

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
    /** @var TranslatorInterface */
    private $translator;
    /** @var StateMachineRegistry */
    private $stateMachineRegistry;
    /** @var BuckarooTransactionEntityRepository */
    private $buckarooTransactionEntityRepository;
    /** @var Connection */
    private $connection;
    /** @var DocumentService */
    protected $documentService;
    /** @var EntityRepositoryInterface */
    private $documentRepository;
    /** * @var MailService */
    private $mailService;
    /** * @var EntityRepositoryInterface */
    private $mailTemplateRepository;
    /** @var EntityRepositoryInterface */
    private $currencyRepository;
    /** @var EntityRepositoryInterface */
    private $salesChannelRepository;
    /** @var LoggerInterface */
    private $logger;

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
        EntityRepositoryInterface $orderRepository,
        TranslatorInterface $translator,
        StateMachineRegistry $stateMachineRegistry,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        Connection $connection,
        DocumentService $documentService,
        EntityRepositoryInterface $documentRepository,
        MailService $mailService,
        EntityRepositoryInterface $mailTemplateRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $salesChannelRepository,
        LoggerInterface $logger
    ) {
        $this->router                              = $router;
        $this->transactionRepository               = $transactionRepository;
        $this->orderTransactionStateHandler        = $orderTransactionStateHandler;
        $this->stateMachineRepository              = $stateMachineRepository;
        $this->shopwareVersion                     = $shopwareVersion;
        $this->pluginService                       = $pluginService;
        $this->settingsService                     = $settingsService;
        $this->helper                              = $helper;
        $this->cartService                         = $cartService;
        $this->orderRepository                     = $orderRepository;
        $this->translator                          = $translator;
        $this->stateMachineRegistry                = $stateMachineRegistry;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->connection = $connection;
        $this->documentService = $documentService;
        $this->documentRepository = $documentRepository;
        $this->mailService = $mailService;
        $this->mailTemplateRepository = $mailTemplateRepository;
        $this->currencyRepository = $currencyRepository;
        $this->salesChannelRepository  = $salesChannelRepository;
        $this->logger = $logger;
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
    public function getTaxRate(CalculatedPrice $calculatedPrice): float
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
    public function getUnitPriceExclTax(CalculatedPrice $calculatedPrice): float
    {
        $unitPrice = $calculatedPrice->getUnitPrice();
        $taxRate   = $this->getTaxRate($calculatedPrice);

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
            if ($transitionAction !== StateMachineTransitionActions::ACTION_PAID) {
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
    public function getCorrectTransitionAction(string $status):  ? string
    {
        switch ($status) {
            case 'completed' :
            case 'paid' :
                return StateMachineTransitionActions::ACTION_PAID;
                break;
            case 'pay_partially' :
                return StateMachineTransitionActions::ACTION_PAY_PARTIALLY;
                break;
            case 'declined':
            case 'cancelled':
            case 'void':
            case 'expired':
                return StateMachineTransitionActions::ACTION_CANCEL;
            case 'fail':
                return StateMachineTransitionActions::ACTION_FAIL;
                break;
            case 'refunded':
                return StateMachineTransitionActions::ACTION_REFUND;
            case 'partial_refunded':
                return StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;
            case 'initialized':
            case 'open':
                return StateMachineTransitionActions::ACTION_REOPEN;
            case 'process':
                return StateMachineTransitionActions::ACTION_PROCESS;
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
        $transaction    = $this->getOrderTransaction($orderTransactionId, $context);
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);
        return $transaction->getStateMachineState()->getTechnicalName() == $stateName;
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
        $criteria  = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $stateName));
        return $this->stateMachineRepository->search($criteria, $context)->first();
    }

    public function isOrderState(array $statuses, string $orderId, Context $context): bool
    {
        $order = $this->getOrderById($orderId, $context);
        foreach($statuses as $status){
            $stateName = $this->getOrderTransactionStatesNameFromAction($status);
            if($order->getStateMachineState()->getTechnicalName() == $stateName){
                return true;
            }
        }
        return false;
    }

    public function isTransitionPaymentState(array $statuses, string $orderTransactionId, Context $context): bool
    {
        foreach($statuses as $status){
            $transitionAction = $this->getCorrectTransitionAction($status);

            if ($transitionAction === null) {
                continue;
            }
            $transaction    = $this->getOrderTransaction($orderTransactionId, $context);
            $actionStatusTransition   = $this->getTransitionFromActionName($transitionAction, $context);
            if($transaction->getStateId() == $actionStatusTransition->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): string
    {
        switch ($actionName) {
            case StateMachineTransitionActions::ACTION_PAID:
                return OrderTransactionStates::STATE_PAID;
                break;
            case StateMachineTransitionActions::ACTION_CANCEL:
                return OrderTransactionStates::STATE_CANCELLED;
                break;
            case StateMachineTransitionActions::ACTION_REFUND:
                return OrderTransactionStates::STATE_REFUNDED;
                break;
            case StateMachineTransitionActions::ACTION_REFUND_PARTIALLY:
                return OrderTransactionStates::STATE_PARTIALLY_REFUNDED;
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
     * @param Context $context
     * @return array
     * @throws \Shopware\Core\Framework\Plugin\Exception\PluginNotFoundException
     */
    public function getPluginMetadata(Context $context): array
    {
        return [
            'shop'           => 'Shopware',
            'shop_version'   => $this->shopwareVersion,
            'plugin_version' => $this->pluginService->getPluginByName('BuckarooPayments', $context)->getVersion(),
            'partner'        => 'Buckaroo',
        ];
    }

    public function getSaleBaseUrl(){
        $checkoutConfirmUrl = $this->router->generate(
            'frontend.checkout.confirm.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        return str_replace('/checkout/confirm','',$checkoutConfirmUrl);
    }

    public function getReturnUrl($route): string
    {
        return $this->getSaleBaseUrl() . $this->router->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    public function saveTransactionData(string $orderTransactionId, Context $context, array $data): void
    {
        $this->logger->info(__METHOD__ . "|1|");
        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $orderTransactionId
        );

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields = array_merge($customFields, $data);

        $this->logger->info(__METHOD__ . "|5|", [$customFields]);
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

    public function updateOrderCustomFields(string $orderId, array $customFields): void
    {
        $order = $this->getOrderById($orderId, false);
        $price = $order->getPrice();

        $buckarooFee = round((float) str_replace(',','.',$customFields['buckarooFee']), 2);
        $data = [
            'id'           => $orderId,
            'customFields' => $customFields,
            'price' => new CartPrice(
                $price->getNetPrice() + $buckarooFee,
                $price->getTotalPrice() + $buckarooFee,
                $price->getPositionPrice(),
                $price->getCalculatedTaxes(),
                $price->getTaxRules(),
                CartPrice::TAX_STATE_GROSS
            )
        ];

        $this->orderRepository->update([$data], Context::createDefaultContext());
    }

    public function getOrderTransactionById(Context $context, string $orderTransactionId):  ? OrderTransactionEntity
    {
        $criteria = new Criteria();
        $filter   = new EqualsFilter('order_transaction.id', $orderTransactionId);
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
    public function getOrder(string $orderId, Context $context) :  ? OrderEntity
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
        $lines     = [];
        $lineItems = $order->getLineItems();

        if ($lineItems === null || $lineItems->count() === 0) {
            return $lines;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        foreach ($lineItems as $item) {
            // Get tax
            $itemTax = null;

            if ($item->getPrice() !== null &&
                $item->getPrice()->getCalculatedTaxes() !== null) {
                $itemTax = $this->getLineItemTax($item->getPrice()->getCalculatedTaxes());
            }

            // Get VAT rate and amount
            $vatRate   = $itemTax !== null ? $itemTax->getTaxRate() : 0.0;
            $vatAmount = $itemTax !== null ? $itemTax->getTax() : null;

            if ($vatAmount === null && $vatRate > 0) {
                $vatAmount = $item->getTotalPrice() * ($vatRate / ($vatRate + 100));
            }

            $type      = 'Article';
            $unitPrice = $this->getPriceArray($currencyCode, $item->getUnitPrice());
            $vatAmount = $this->getPriceArray($currencyCode, $vatAmount);
            if ($unitPrice['value'] < 0) {
                $type               = 'Discount';
                $vatAmount['value'] = 0;
                $vatRate            = 0.0;
            }

            // Build the order lines array
            $lines[] = [
                'id'          => $item->getId(),
                'type'        => $type,
                'name'        => $item->getLabel(),
                'quantity'    => $item->getQuantity(),
                'unitPrice'   => $unitPrice,
                'totalAmount' => $this->getPriceArray($currencyCode, $item->getTotalPrice()),
                'vatRate'     => number_format($vatRate, 2, '.', ''),
                'vatAmount'   => $vatAmount,
                'sku'         => $item->getId(),
                'imageUrl'    => null,
                'productUrl'  => null,
            ];
        }

        $lines[] = $this->getShippingItemArray($order);

        if($this->getBuckarooFeeArray($order)){
            $lines[] = $this->getBuckarooFeeArray($order);
        }

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
        $line     = [];
        $shipping = $order->getShippingCosts();

        if ($shipping === null) {
            return $line;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        // Get shipping tax
        $shippingTax = null;

        if ($shipping->getCalculatedTaxes() !== null) {
            $shippingTax = $this->getLineItemTax($shipping->getCalculatedTaxes());
        }

        // Get VAT rate and amount
        $vatRate   = $shippingTax !== null ? $shippingTax->getTaxRate() : 0.0;
        $vatAmount = $vatAmount = $shippingTax !== null ? $shippingTax->getTax() : null;

        if ($vatAmount === null && $vatRate > 0) {
            $vatAmount = $shipping->getTotalPrice() * ($vatRate / ($vatRate + 100));
        }

        // Build the order line array
        $line = [
            'id'          => 'shipping',
            'type'        => 'Shipping',
            'name'        => 'Shipping',
            'quantity'    => $shipping->getQuantity(),
            'unitPrice'   => $this->getPriceArray($currencyCode, $shipping->getUnitPrice()),
            'totalAmount' => $this->getPriceArray($currencyCode, $shipping->getTotalPrice()),
            'vatRate'     => number_format($vatRate, 2, '.', ''),
            'vatAmount'   => $this->getPriceArray($currencyCode, $vatAmount),
            'sku'         => 'Shipping',
            'imageUrl'    => null,
            'productUrl'  => null,
        ];

        return $line;
    }

    public function getBuckarooFeeArray(OrderEntity $order)
    {
        // Variables
        $line     = [];
        $customFields = $order->getCustomFields();

        if ($customFields === null || !isset($customFields['buckarooFee'])) {
            return false;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';
        $buckarooFee = round(str_replace(',','.',(float) $customFields['buckarooFee']), 2);

        // Build the order line array
        $line = [
            'id'          => 'buckarooFee',
            'type'        => 'BuckarooFee',
            'name'        => 'Buckaroo Fee',
            'quantity'    => 1,
            'unitPrice'   => $this->getPriceArray($currencyCode, $buckarooFee),
            'totalAmount' => $this->getPriceArray($currencyCode, $buckarooFee),
            'vatRate'     => 0,
            'vatAmount'   => $this->getPriceArray($currencyCode, 0),
            'sku'         => 'BuckarooFee',
            'imageUrl'    => null,
            'productUrl'  => null,
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
    public function getPriceArray(string $currency, float $price, int $decimals = 2): array
    {
        return [
            'currency' => $currency,
            'value'    => number_format($price, $decimals, '.', ''),
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
    public function getCustomer(string $customerId, Context $context):  ? CustomerEntity
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

    public function getOrderCustomer($order, $salesChannelContext)
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
        return $customer;
    }

    public function getBillingAddress($order, $salesChannelContext)
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

        return $customer->getDefaultBillingAddress();
    }

    public function getShippingAddress($order, $salesChannelContext)
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

        return $customer->getDefaultShippingAddress();
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
            'street'          => $street,
        ];

        if (preg_match('#^(.*?)([0-9]+)(.*)#s', $street, $matches)) {
            // Check if the number is at the beginning of streetname
            if ('' == $matches[1]) {
                $format['house_number'] = trim($matches[2]);
                $format['street']       = trim($matches[3]);
            } else {
                if (preg_match('#^(.*?)([0-9]+)(.*)#s', $street, $matches)) {
                    $format['street']          = trim($matches[1]);
                    $format['house_number']    = trim($matches[2]);
                    $format['number_addition'] = trim(str_replace(',', '', $matches[3]));
                }
            }
        }
        return $format;
    }

    public function getRefundArticleData($amount)
    {

        $additional[] = [
            [
                '_'       => 'Return',
                'Name'    => 'RefundType',
                'GroupID' => 1,
                'Group'   => 'Article',
            ], [
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group'   => 'Article',
            ], [
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => '1',
                'Name'    => 'Identifier',
                'Group'   => 'Article',
                'GroupID' => 1,
            ],
            [
                '_'       => '1',
                'Name'    => 'Quantity',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => $amount,
                'Name'    => 'GrossUnitPrice',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'VatPercentage',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
        ];

        return $additional;
    }

    public function getRefundBillinkArticleData($amount)
    {
        $additional[] = [
            [
                '_'       => $amount,
                'Name'    => 'GrossUnitPriceIncl',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'VatPercentage',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
        ];

        return $additional;
    }

    public function getTransferData($order, $additional, $salesChannelContext, $dataBag)
    {
        $address = $this->getBillingAddress($order, $salesChannelContext);
        $customer = $this->getOrderCustomer($order, $salesChannelContext);

        if ($address === null) {
            return $additional;
        }

        $now = new \DateTime();
        $now->modify('+' . ($this->getSetting('transferDueDate') > 0 ? $this->getSetting('transferDueDate') : 7) . ' day');
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
                'Name' => 'DateDue',
            ],
            [
                '_'    => $sendEmail,
                'Name' => 'SendMail',
            ],
        ];

        return array_merge($additional, [$services]);
    }

    /**
     * @param $locale
     * @return string
     */
    public static function getTranslatedLocale($locale = false) : string
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
    public function getBaseUrl($method = ''): string
    {
        return $this->helper->getEnvironment($method) == 'live' ? UrlHelper::LIVE : UrlHelper::TEST;
    }

    /**
     * @return string Full transaction url
     */
    public function getTransactionUrl($method = ''): string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/Transaction', '/');
    }

    /**
     * @return string Full transaction url
     */
    public function getApiTestUrl($method = ''): string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/DataRequest/Specifications', '/');
    }

    /**
     * @return string Full transaction url
     */
    public function getDataRequestUrl($method = ''): string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/DataRequest', '/');
    }

    public function addLineItems($lineItems, SalesChannelContext $salesChannelContext)
    {
        $count = 0;
        try {
            $cart = new Cart('recalculation', Uuid::randomHex());
            foreach ($lineItems as $lineItemDatas) {
                foreach ($lineItemDatas as $referencedId => $lineItemData) {

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

        $method_path                = str_replace('Handlers', 'PaymentMethods', str_replace('PaymentHandler', '', $transaction->getPaymentMethod()->getHandlerIdentifier()));
        $paymentMethod              = new $method_path;
        $customField['canRefund']   = $paymentMethod->canRefund() ? 1 : 0;
        $customField['canCapture']   = $paymentMethod->canCapture() ? 1 : 0;
        $customField['serviceName'] = $paymentMethod->getBuckarooKey();
        $customField['version']     = $paymentMethod->getVersion();

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

        $baseClassArr         = explode('\\', $plugin->getBaseClass());
        $buckarooPaymentClass = explode('\\', BuckarooPayments::class);

        return end($baseClassArr) === end($buckarooPaymentClass);
    }

    public function getOrderById($orderId, $context):  ? OrderEntity
    {
        $context       = $context ? $context : Context::createDefaultContext();
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');

        return $this->orderRepository->search($orderCriteria, $context)->first();
    }

    public function refundTransaction($order, $context, $item, $state, &$orderItems = '')
    {
        if (!$this->isBuckarooPaymentMethod($order)) {
            return;
        }

        $customFields = $this->getCustomFields($order, $context);

        $customFields['serviceName']            = $item['transaction_method'];
        $customFields['originalTransactionKey'] = $item['transactions'];
        $amount                                 = $item['amount'];
        $currency                               = !empty($item['currency']) ? $item['currency'] : 'EUR';

        if ($amount <= 0) {
            return false;
        }

        if ($customFields['canRefund'] == 0) {
            return ['status' => false, 'message' => 'Refund is not supported'];
        }

        if (!empty($customFields['refunded']) && ($customFields['refunded'] == 1)) {
            return ['status' => false, 'message' => 'This order is already refunded'];
        }

        $serviceName = (in_array($customFields['serviceName'], ['creditcard','creditcards', 'giftcards'])) ? $customFields['brqPaymentMethod'] : $customFields['serviceName'];

        $request = new TransactionRequest;
        $request->setServiceAction('Refund');
        $request->setDescription($this->getTranslate('buckaroo.order.refundDescription', ['orderNumber' => $order->getOrderNumber()]));
        $request->setServiceName($serviceName);
        $request->setAmountCredit($amount ? $amount : $order->getAmountTotal());
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($currency);
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);
        $request->setServiceVersion($customFields['version']);

        if ($customFields['serviceName'] == 'afterpay') {
            $additional = $this->getRefundArticleData($amount);
            foreach ($additional as $key2 => $item3) {
                foreach ($item3 as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        if ($customFields['serviceName'] == 'Billink') {
            $additional = $this->getRefundBillinkArticleData($amount);
            foreach ($additional as $key2 => $item3) {
                foreach ($item3 as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        if ($customFields['serviceName'] == 'klarnakp') {
            $additional = $this->getRefundArticleData($amount);
            foreach ($additional as $key2 => $item3) {
                foreach ($item3 as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        if ($customFields['serviceName'] == 'sepadirectdebit') {
            $request->setChannelHeader('Backoffice');
        }

        $url       = $this->getTransactionUrl($customFields['serviceName']);
        $bkrClient = $this->helper->initializeBkr();
        $response  = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');

        if ($response->isSuccess()) {
            $transaction = $order->getTransactions()->first();
            $status      = ($amount < $order->getAmountTotal()) ? 'partial_refunded' : 'refunded';
            $this->transitionPaymentState($status, $transaction->getId(), $context);
            $this->saveTransactionData($transaction->getId(), $context, [$status => 1]);

            // updating refunded items in transaction
            if ($orderItems) {
                foreach ($orderItems as $value) {
                    if(isset($value['id'])){
                        $orderItemsRefunded[$value['id']] = $value['quantity'];
                    }
                }
                $orderItems = '';

                $refunded_items = $this->buckarooTransactionEntityRepository->getById($item['id'])->get("refunded_items");
                if ($refunded_items) {
                    $refunded_items = json_decode($refunded_items);
                    foreach ($refunded_items as $k => $qnt) {
                        if ($orderItemsRefunded[$k]) {
                            $orderItemsRefunded[$k] = (int)$orderItemsRefunded[$k] + (int)$qnt;
                        } else {
                            $orderItemsRefunded[$k] = (int)$qnt;
                        }
                    }
                }

                $this->buckarooTransactionEntityRepository->save($item['id'], ['refunded_items' => json_encode($orderItemsRefunded)], []);
            }

            return ['status' => true, 'message' => 'Buckaroo success refunded ' . $amount . ' ' . $currency];
        }

        return [
            'status'  => false,
            'message' => $response->getSubCodeMessageFull() ?? $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    public function captureTransaction($order, $context)
    {
        $this->logger->info(__METHOD__ . "|1|", [$order]);
        if (!$this->isBuckarooPaymentMethod($order)) {
            return;
        }

        $customFields = $this->getCustomFields($order, $context);

        $amount                                 = $order->getAmountTotal();
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        $this->logger->info(__METHOD__ . "|10|", [$customFields, $amount, $currencyCode]);

        if ($amount <= 0) {
            $this->logger->info(__METHOD__ . "|15|");
            return ['status' => false, 'message' => 'Amount is not valid'];
        }

        if ($customFields['canCapture'] == 0) {
            $this->logger->info(__METHOD__ . "|20|");
            return ['status' => false, 'message' => 'Capture is not supported'];
        }

        $this->logger->info(__METHOD__ . "|25|");

        if (!empty($customFields['captured']) && ($customFields['captured'] == 1)) {
            $this->logger->info(__METHOD__ . "|30|");
            return ['status' => false, 'message' => 'This order is already captured'];
        }

        $request = new TransactionRequest;
        $request->setServiceAction('Pay');
        //$request->setDescription($this->getTranslate('buckaroo.order.refundDescription', ['orderNumber' => $order->getOrderNumber()]));
        $request->setDescription('');
        $request->setServiceName($customFields['serviceName']);
        $request->setAmountCredit(0);
        $request->setAmountDebit($amount);
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($currencyCode);
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);
        $request->setServiceVersion($customFields['version']);

        $request->setAdditionalParameter('orderTransactionId', $order->getTransactions()->last()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setPushURL($this->getReturnUrl('buckaroo.payment.push'));

        if ($customFields['serviceName'] == 'klarnakp') {

            $orderItems = $this->getProductLineDataCapture($order);
            foreach ($orderItems as $value) {
                $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
            }

            $request->setServiceParameter('ReservationNumber', $customFields['reservationNumber']);
        }

        $url       = $this->getTransactionUrl($customFields['serviceName']);
        $bkrClient = $this->helper->initializeBkr();
        $response  = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');

        if ($response->isSuccess()) {
            $this->logger->info(__METHOD__ . "|45|");

            //$transaction = $order->getTransactions()->first();
            //$status      = ($amount < $order->getAmountTotal()) ? 'pay_partially' : 'completed';
            //$this->transitionPaymentState($status, $transaction->getId(), $context);
            //$this->saveTransactionData($transaction->getId(), $context, [$status => 1]);

            if (!$this->isInvoiced($order->getId(), $context)) {
                $this->logger->info(__METHOD__ . "|55|");
                $this->generateInvoice($order->getId(), $context, $order->getId());
            }

            return ['status' => true, 'message' => 'Amount '.$currency . $amount. ' has been captured!'];
        }

        $this->logger->info(__METHOD__ . "|60|");

        return [
            'status'  => false,
            'message' => $response->getSubCodeMessageFull() ?? $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    /**
     * Generate/calculate the signature with the buckaroo config value and check if thats equal to the signature
     * received from the push
     *
     * @return bool
     */
    public function validateSignature()
    {
        $request  = $this->helper->getGlobals();
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

            $signatureString .= $brq_key . '=' . $value;
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
            case 'brq_SERVICE_payconiq_PayconiqAndroidUrl' :
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
            $key                 = $originalArray[$key];
            $sortableArray[$key] = $value;
        }

        return $sortableArray;
    }

    public function getTranslate($id, array $parameters = [])
    {
        return $this->translator->trans($id, $parameters);
    }

    public function changeOrderStatus(string $orderId, Context $context, $transitionName): void
    {
        if($this->isOrderState([$transitionName], $orderId, $context)){
            return;
        }

        if (isset($transitionName)) {
            try {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId,
                        $transitionName,
                        'stateId'
                    ),
                    $context
                );
            } catch (Exception $e) {
                $this->logger->addEntry(
                    $e->getMessage(),
                    $context,
                    $e,
                    [
                        'function' => 'change-order-status',
                    ]
                );
            }
        }

        return;
    }

    public function getBuckarooTransactionsByOrderId($orderId)
    {
        $transactionsToRefund = [];
        $order = $this->getOrderById($orderId, false);
        $vat   = $order->get("price")->getTaxRules()->first()->getTaxRate();

        $vat_show  = null;
        $ii=0;
        foreach ($order->get("price")->getTaxRules()->getElements() as $taxRate) {
            $vat_show .= ($ii>0?"/":"")."plus ".$taxRate->getTaxRate()."% VAT";$ii++;
        }

        $items['orderItems'] = $this->getOrderLinesArray($order);

        $shipping       = $order->getShippingCosts();
        $shipping_costs = $shipping->getTotalPrice();

        $orderRefundedItems = [];

        $collection = $this->buckarooTransactionEntityRepository->findByOrderId($orderId, ['created_at' => 'DESC']);
        foreach ($collection as $buckarooTransactionEntity) {
            if ($refunded_items = $buckarooTransactionEntity->get("refunded_items")) {
                $orderRefundedItems[] = json_decode($refunded_items);
            }
            if(!$buckarooTransactionEntity->get("transaction_method")){continue;}
            $transactions = $buckarooTransactionEntity->get("transactions");
            if(in_array($transactions, $transactionsToRefund)){continue;}
            array_push($transactionsToRefund, $transactions);
            $amount                  = $buckarooTransactionEntity->get("amount_credit") ? '-' . $buckarooTransactionEntity->get("amount_credit") : $buckarooTransactionEntity->get("amount");
            $items['transactions'][] = (object) [
                'id'                  => $buckarooTransactionEntity->get("id"),
                'transaction'         => $buckarooTransactionEntity->get("transactions"),
                'statuscode'          => $buckarooTransactionEntity->get("statuscode"),
                'total'               => $amount,
                'shipping_costs'      => $shipping_costs,
                'vat'                 => $vat_show,
                'total_excluding_vat' => $vat ? round(($amount - (($amount / 100) * $vat)), 2) : $amount,
                'transaction_method'  => $buckarooTransactionEntity->get("transaction_method"),
                'created_at'          => Date("Y-m-d H:i:s", strtotime($buckarooTransactionEntity->get("created_at")->date)),
            ];

            if ($buckarooTransactionEntity->get("amount") && $buckarooTransactionEntity->get("statuscode")==ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
                $items['transactionsToRefund'][] = (object) [
                    'id'                 => $buckarooTransactionEntity->get("id"),
                    'transactions'       => $transactions,
                    'total'              => $amount,
                    'currency'           => $buckarooTransactionEntity->get("currency"),
                    'transaction_method' => $buckarooTransactionEntity->get("transaction_method"),
                ];
            }
        }

        foreach ($orderRefundedItems as $key => $value) {
            foreach ($value as $key3 => $quantity) {
                foreach ($items['orderItems'] as $key2 => $value2) {
                    if (isset($value2['id']) && $key3 == $value2['id']) {
                        $items['orderItems'][$key2]['quantity'] = (int)$value2['quantity'] - (int)$quantity;
                        $items['orderItems'][$key2]['totalAmount']['value'] = ((float)$value2['totalAmount']['value'] - ((float)$value2['unitPrice']['value'] * (int)$quantity));
                        if ($items['orderItems'][$key2]['quantity'] < 0) {$items['orderItems'][$key2]['quantity'] = 0;}
                    }
                }
            }
        }
        return $items;
    }

    public function saveBuckarooTransaction(Request $request, Context $context)
    {
        return $this->buckarooTransactionEntityRepository->save(null, $this->pusToArray($request), []);
    }

    public function pusToArray(Request $request): array
    {
        $now  = new \DateTime();
        $type = 'push';
        if ($request->request->get('brq_transaction_type') == 'I150') {
            $type = 'info';
        }
        return [
            'order_id'             => $request->request->get('ADD_orderId'),
            'order_transaction_id' => $request->request->get('ADD_orderTransactionId'),
            'amount'               => $request->request->get('brq_amount'),
            'amount_credit'        => $request->request->get('brq_amount_credit'),
            'currency'             => $request->request->get('brq_currency'),
            'ordernumber'          => $request->request->get('brq_invoicenumber'),
            'statuscode'           => $request->request->get('brq_statuscode'),
            'transaction_method'   => $request->request->get('brq_transaction_method'),
            'transaction_type'     => $request->request->get('brq_transaction_type'),
            'transactions'         => $request->request->get('brq_transactions'),
            'relatedtransaction'   => $request->request->get('brq_relatedtransaction_partialpayment'),
            'type'                 => $type,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    private function getProductsOfOrder(string $orderId): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['referenced_id', 'quantity']);
        $query->from('order_line_item');
        $query->andWhere('type = :type');
        $query->andWhere('order_id = :id');
        $query->setParameter('id', Uuid::fromHexToBytes($orderId));
        $query->setParameter('type', LineItem::PRODUCT_LINE_ITEM_TYPE);

        return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function stockReserve(OrderEntity $order){
        $products = $this->getProductsOfOrder($order->getId());
        
        $query = new RetryableQuery(
            $this->connection->prepare('UPDATE product SET stock = stock - :quantity WHERE id = :id AND version_id = :version')
        );

        foreach ($products as $product) {
            $query->execute([
                'quantity' => (int) $product['quantity'],
                'id' => Uuid::fromHexToBytes($product['referenced_id']),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]);
        }
    }

    public function isInvoiced($orderId, $context){
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', InvoiceGenerator::INVOICE));

        /** @var DocumentEntity|null $documentEntity */
        $documentEntity = $this->documentRepository->search($criteria, $context);

        if (($documentEntity === null) || ($documentEntity->first() == null)) {
            return false;
        }

        return true;
    }

    private function getMailTemplate(Context $context, string $technicalName, OrderEntity $order): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);

        if ($order->getSalesChannelId()) {
            $criteria->addFilter(
                new EqualsFilter('mail_template.salesChannels.salesChannel.id', $order->getSalesChannelId())
            );
        }

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }

    /**
     * @throws InvalidDocumentException
     */
    private function getDocument(string $documentId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $documentId));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');

        /** @var DocumentEntity|null $documentEntity */
        $documentEntity = $this->documentRepository->search($criteria, $context)->get($documentId);

        if ($documentEntity === null) {
            throw new InvalidDocumentException($documentId);
        }

        $document = $this->documentService->getDocument($documentEntity, $context);

        return [
            'content' => $document->getFileBlob(),
            'fileName' => $document->getFilename(),
            'mimeType' => $document->getContentType(),
        ];
    }
    
    /**
     * @param string[] $documentIds
     */
    private function sendMail(
        Context $context,
        MailTemplateEntity $mailTemplate,
        OrderEntity $order,
        ? array $documentIds
    ): void {
        $customer = $order->getOrderCustomer();
        if ($customer === null) {
            return;
        }

        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName() . ' ' . $customer->getLastName(),
            ]
        );

        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $order->getSalesChannelId());
        $contentHtml = 'Hello! Your invoice attached';
        $data->set('contentHtml', $contentHtml);
        $data->set('contentPlain', $contentHtml);
        $data->set('subject', $mailTemplate->getTranslation('subject'));

        $documents = [];
        foreach ($documentIds as $documentId) {
            $documents[] = $this->getDocument($documentId, $context);
        }

        if (!empty($documents)) {
            $data->set('binAttachments', $documents);
        }

        // getting the correct sales channel domain with the help of the languageId of the order
        $languageId = $order->getLanguageId();
        $salesChannelCriteria = new Criteria([$order->getSalesChannel()->getId()]);
        $salesChannelCriteria->getAssociation('domains')
            ->addFilter(
                new EqualsFilter('languageId', $languageId)
            );

        $salesChannel = $this->salesChannelRepository->search($salesChannelCriteria, $context)->first();
        $this->mailService->send(
            $data->all(),
            $context,
            [
                'order' => $order,
                'salesChannel' => $salesChannel,
            ]
        );

        $writes = array_map(static function ($id) {
            return ['id' => $id, 'sent' => true];
        }, $documentIds);

        if (!empty($writes)) {
            $this->documentRepository->update($writes, $context);
        }
    }

    public function generateInvoice($orderId, $context, $invoiceNumber){
        $documentConfiguration = new DocumentConfiguration();
        $documentConfiguration->setDocumentNumber($invoiceNumber);
        $invoice = $this->documentService->create(
            $orderId,
            InvoiceGenerator::INVOICE,
            FileTypes::PDF,
            $documentConfiguration,
            $context
        );

        if($this->helper->getSettingsValue('sendInvoiceEmail')){
            $documentIds = [$invoice->getId()];
            $technicalName = 'order_transaction.state.paid';
            $order = $this->getOrderById($orderId, $context);
            $mailTemplate = $this->getMailTemplate($context, $technicalName, $order);

            if ($mailTemplate !== null) {
                $context = Context::createDefaultContext();
                $this->sendMail(
                    $context,
                    $mailTemplate,
                    $order,
                    $documentIds
                );
            }
        }

        return $invoice;
    }

    /**
     * @param string          $value
     * @param string          $name
     * @param null|string     $groupType
     * @param null|string|int $groupId
     *
     * @return array
     */
    public function getRequestParameterRow($value, $name, $groupType = null, $groupId = null)
    {
        $row = [
            '_' => $value,
            'Name' => $name
        ];

        if ($groupType !== null) {
            $row['Group'] = $groupType;
        }

        if ($groupId !== null) {
            $row['GroupID'] = $groupId;
        }

        return $row;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public function getInitials($name)
    {
        $initials = '';
        $nameParts = explode(' ', $name);

        if (empty($nameParts)) {
            return $initials;
        }

        foreach ($nameParts as $part) {
            $initials .= strtoupper(substr($part, 0, 1)) . '.';
        }

        return $initials;
    }

    /**
     * @param CustomerEntity $customer
     * @return string|null
     */
    public function getGenderFromSalutation(CustomerEntity $customer, $format = ''): ?string
    {
        switch ($customer->getSalutation()->getSalutationKey()) {
            case 'mr':
                return $format?'Male':'1';
            case 'mrs':
                return $format?'Female':'2';
        }
        return $format?'Unknown':'0';
    }

    public function getProductLineData($order)
    {
        $lines = $this->getOrderLinesArray($order);

        $productData = [];
        $max = 99;
        $i = 1;

        foreach ($lines as $item) {
            $productData[] = $this->getRequestParameterRow($item['sku'], 'Code', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['name'], 'Name', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['quantity'], 'Quantity', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['unitPrice']['value'], 'Price', 'ProductLine', $i);

            $i++;

            if ($i > $max) {
                break;
            }
        }

        return $productData;
    }

    public function getProductLineDataCapture($order)
    {
        $lines = $this->getOrderLinesArray($order);

        $productData = [];
        $max = 99;
        $i = 1;

        foreach ($lines as $item) {
            $productData[] = $this->getRequestParameterRow($item['sku'], 'ArticleNumber', 'Article', $i);
            $productData[] = $this->getRequestParameterRow($item['quantity'], 'ArticleQuantity', 'Article', $i);

            $i++;

            if ($i > $max) {
                break;
            }
        }

        return $productData;
    }

    public function getOrderCurrency(Context $context): CurrencyEntity
    {
        $criteria = new Criteria([$context->getCurrencyId()]);

        /** @var null|CurrencyEntity $currency */
        $currency = $this->currencyRepository->search($criteria, $context)->first();

        if (null === $currency) {
            throw new RuntimeException('missing order currency entity');
        }

        return $currency;
    }

    /**
     * Detect Mobile Browser
     * @return boolean [description]
     */
    public function isMobile(): bool
    {
        $useragent = Helpers::getRemoteUserAgent();
        if(preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',$useragent)||preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',substr($useragent,0,4))){
            return true;
        }
        return false;
    }

    public function getBuckarooFeeLabel($buckarooKey, $label, $context)
    {
        $currency = $this->getOrderCurrency($context);
        if($buckarooFee = $this->getSetting($buckarooKey.'Fee')){
            $label .= ' +' . $currency->getSymbol() . $buckarooFee;
        }
        return $label;
    }

    public function getBuckarooFee($buckarooKey)
    {
        if($buckarooFee = $this->getSetting($buckarooKey)){
            return round(str_replace(',','.',$buckarooFee), 2);
        }
        return false;
    }

    public function getSettingsValue($value){
        return $this->helper->getSettingsValue($value);
    }

    public function forwardToRoute($path,$parameters = []){
        return $this->router->generate($path, $parameters);
    }

    public function getBuckarooApiTest($websiteKeyId, $secretKeyId){
        $this->settingsService->setSetting('websiteKey', $websiteKeyId);
        $this->settingsService->setSetting('secretKey', $secretKeyId);
        $request = new TransactionRequest;
        $request->setServiceName('ideal');
        $request->setServiceVersion('2');

        $url       = $this->getTransactionUrl('ideal');
        $bkrClient = $this->helper->initializeBkr();
        try {
            $response  = $bkrClient->post($url, $request);
            if($response->getHttpCode() == '200'){
                return [
                    'status' => 'success',
                    'message' => 'Connection ready',
                ];
            }
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Connection failed',
            ];
        }
    }
    
}
