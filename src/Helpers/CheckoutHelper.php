<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Helpers;

use Shopware\Core\Defaults;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Document\DocumentService;
use Symfony\Component\HttpFoundation\Session\Session;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
 
class CheckoutHelper
{

    /** * @var string */
    private $shopwareVersion;
    /** @var SettingsService $settingsService */
    private $settingsService;
    /** @var EntityRepositoryInterface $orderRepository */
    private $orderRepository;
    /** @var BuckarooTransactionEntityRepository */
    private $buckarooTransactionEntityRepository;
    /** @var Connection */
    private $connection;
    /** @var DocumentService */
    protected $documentService;

    protected Session $session;
    /**
     * CheckoutHelper constructor.
     * @param string $shopwareVersion
     */
    public function __construct(
        string $shopwareVersion,
        SettingsService $settingsService,
        EntityRepositoryInterface $orderRepository,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        Session $session,
        Connection $connection
    ) {
        $this->shopwareVersion                     = $shopwareVersion;
        $this->settingsService                     = $settingsService;
        $this->orderRepository                     = $orderRepository;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->session = $session;
        $this->connection = $connection;


    }
    
    public function getSession(): Session
    {
        return $this->session;
    }
    
    public function getShopwareVersion()
    {
        return $this->shopwareVersion;
    }
    
    public function applyFeeToOrder(string $orderId, array $customFields): void
    {
        $order = $this->getOrderById($orderId, false);
        $price = $order->getPrice();

        $savedCustomFields = $order->getCustomFields();

        if($savedCustomFields === null) {
            $savedCustomFields = [];
        }

        if(!isset($savedCustomFields['buckarooFee'])) {
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
 
    public function getSettingsValue($value, string $salesChannelId = null){
        return $this->settingsService->getSetting($value, $salesChannelId);
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
