<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Defaults;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Document\DocumentService;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;

class CheckoutHelper
{

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
    /** @var BuckarooTransactionEntityRepository */
    private $buckarooTransactionEntityRepository;
    /** @var Connection */
    private $connection;
    /** @var DocumentService */
    protected $documentService;
    /** @var EntityRepositoryInterface */
    private $currencyRepository;
    /** @var LoggerInterface */
    private $logger;
    /** @var Session */
    private $session;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepository;

    /**
     * CheckoutHelper constructor.
     * @param string $shopwareVersion
     * @param PluginService $pluginService
     */
    public function __construct(
        string $shopwareVersion,
        PluginService $pluginService,
        SettingsService $settingsService,
        EntityRepositoryInterface $orderRepository,
        TranslatorInterface $translator,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        Connection $connection,
        EntityRepositoryInterface $currencyRepository,
        Session $session,
        LoggerInterface $logger,
        EntityRepositoryInterface $languageRepository
    ) {
        $this->shopwareVersion                     = $shopwareVersion;
        $this->pluginService                       = $pluginService;
        $this->settingsService                     = $settingsService;
        $this->orderRepository                     = $orderRepository;
        $this->translator                          = $translator;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->connection = $connection;

        $this->currencyRepository = $currencyRepository;
        $this->session = $session;
        $this->logger = $logger;
        $this->languageRepository = $languageRepository;

    }

    public function getSetting(string $name, string $salesChannelId = null)
    {
        return $this->settingsService->getSetting($name, $salesChannelId);
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


    public function getShopwareVersion()
    {
        return $this->shopwareVersion;
    }
    
    public function updateOrderCustomFields(string $orderId, array $customFields): void
    {
        $order = $this->getOrderById($orderId, false);
        $price = $order->getPrice();

        $savedCustomFields = $order->getCustomFields();

        if($savedCustomFields === null) {
            $savedCustomFields = [];
        }

        $buckarooFee = round((float) str_replace(',','.',$customFields['buckarooFee']), 2);
        $data = [
            'id'           => $orderId,
            'customFields' => array_merge($savedCustomFields, $customFields),
            'price' => new CartPrice(
                $price->getNetPrice() + $buckarooFee,
                $price->getTotalPrice() + $buckarooFee,
                $price->getPositionPrice(),
                $price->getCalculatedTaxes(),
                $price->getTaxRules(),
                $price->getTaxStatus()
            )
        ];

        $this->orderRepository->update([$data], Context::createDefaultContext());
    }

    /**
     * Append additional data to order custom fields 
     *
     * @param string $orderId
     * @param array $customFields
     *
     * @return void
     */
    public function appendCustomFields(string $orderId, array $customFields) {
        $order = $this->getOrderById($orderId, false);
        $savedCustomFields = $order->getCustomFields();

        if($savedCustomFields === null) {
            $savedCustomFields = [];
        }
        $this->orderRepository->update(
            [['id' => $orderId, 'customFields' => array_merge($savedCustomFields, $customFields)]],
            Context::createDefaultContext()
        );
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

    /**
     * Get customer from order or from context
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return CustomerEntity
     */
    public function getOrderCustomer(OrderEntity $order, SalesChannelContext $salesChannelContext)
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

    /**
     * Get billing address from order,
     * or default address from user if not found
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return null|\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
     * |\Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity
     */
    public function getBillingAddress(OrderEntity $order, SalesChannelContext $salesChannelContext)
    {
        if($order->getBillingAddress() !== null) {
            return $order->getBillingAddress();
        }

        $customer = $this->getOrderCustomer($order, $salesChannelContext);
        return $customer->getDefaultBillingAddress();
    }

    /** Get first shipping address from order,
    * or default address from user if not found
    *
    * @param OrderEntity $order
    * @param SalesChannelContext $salesChannelContext
    *
    * @return null|\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
    * |\Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity
    */
    public function getShippingAddress(OrderEntity $order, SalesChannelContext $salesChannelContext)
    {
        $deliveries = $order->getDeliveries();

        if(
            $deliveries !== null &&
            $deliveries->getShippingAddress() !== null &&
            $deliveries->getShippingAddress()->first() !== null
        ) {
            return $deliveries->getShippingAddress()->first();
        }

        $customer = $this->getOrderCustomer($order, $salesChannelContext);
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

    


    public function getTransferData($order, $additional, $salesChannelContext, $dataBag)
    {
        $address = $this->getBillingAddress($order, $salesChannelContext);
        $customer = $this->getOrderCustomer($order, $salesChannelContext);

        if ($address === null) {
            return $additional;
        }

        $now = new \DateTime();
        $now->modify('+' . ($this->getSetting('transferDateDue', $salesChannelContext->getSalesChannelId()) > 0 ? $this->getSetting('transferDateDue', $salesChannelContext->getSalesChannelId()) : 7) . ' day');
        $sendEmail = $this->getSetting('transferSendEmail', $salesChannelContext->getSalesChannelId()) ? 'true' : 'false';

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


    public function getTranslate($id, array $parameters = [])
    {
        return $this->translator->trans($id, $parameters);
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


    public function getBuckarooFee($buckarooKey, string $salesChannelId = null)
    {
        return $this->settingsService->getBuckarooFee($buckarooKey, $salesChannelId);
    }

    public function getSettingsValue($value, string $salesChannelId = null){
        return $this->settingsService->getSetting($value, $salesChannelId);
    }

    

    public function getCountryCode($address){
        return $address->getCountry() !== null && $address->getCountry()->getIso() !== null ? $address->getCountry()->getIso() : 'NL';
    }

    public function getSalesChannelLocaleCode(SalesChannelContext $context)
    {
        $criteria = (new Criteria([$context->getSalesChannel()->getLanguageId()]))
            ->addAssociation('locale');

        /** @var \Shopware\Core\System\Language\LanguageEntity|null */
        $language = $this->languageRepository
            ->search($criteria,$context->getContext())
            ->first();

        if($language !== null && $language->getLocale() !== null) {
            return $language->getLocale()->getCode();
        }

        return "en-GB";
    }

    public function getStatusMessageByStatusCode($statusCode)
    {
        $statusCodeAddErrorMessage = [];
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_FAILED] = 
        $this->getTranslate('buckaroo.messages.statuscode_failed');
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_REJECTED] =
            $this->getTranslate('buckaroo.messages.statuscode_failed');
        $statusCodeAddErrorMessage[ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER] =
            $this->getTranslate('buckaroo.messages.statuscode_cancelled_by_user');
            return $statusCodeAddErrorMessage[$statusCode] ?? '';
    }

    public function getSession()
    {
        return $this->session;
    }

    public function areEqualAmounts($amount1, $amount2)
    {
        if ($amount2 == 0) {
            return $amount1 == $amount2;
        } else {
            return abs((floatval($amount1) - floatval($amount2)) / floatval($amount2)) < 0.00001;
        }
    }
}
