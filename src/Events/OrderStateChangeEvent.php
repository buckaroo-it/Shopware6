<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Events;

use Buckaroo\Shopware6\Helper\ApiHelper;
use Buckaroo\Shopware6\BuckarooPayment;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

use Buckaroo\Shopware6\API\Payload\TransactionRequest;
use Buckaroo\Shopware6\Helper\UrlHelper;

use Buckaroo\Shopware6\Helper\CheckoutHelper;

use Psr\Log\LoggerInterface;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    /** @var EntityRepositoryInterface */
    private $orderRepository;
    /** @var EntityRepositoryInterface */
    private $orderDeliveryRepository;
    /** @var ApiHelper */
    private $apiHelper;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderDeliveryRepository
     * @param ApiHelper $apiHelper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderDeliveryRepository,
        ApiHelper $apiHelper,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->apiHelper = $apiHelper;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.refunded' => 'onOrderTransactionRefunded',
            'state_enter.order_transaction.state.refunded_partially' => 'onOrderTransactionRefundedPartially',
        ];
    }

    public function sendTransactionRefund(OrderStateMachineStateChangeEvent $event, $state)
    {
        $order = $this->getOrder($event);
        $customFields = $this->getCustomFields($order, $event);

        if (!$this->isBuckarooPaymentMethod($order)) {
            return;
        }

        if($customFields['refund']==0){
            return false;
        }

        $request = new TransactionRequest;
        $request->setServiceAction('Refund');
        $request->setDescription('Refund for order #' . $order->getOrderNumber());
        $request->setServiceName($customFields['serviceName']);
        $request->setAmountCredit($order->getAmountTotal());
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency('EUR');
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);

        $url = $this->getTransactionUrl($customFields['serviceName']);
        $bkrClient = $this->apiHelper->initializeBuckarooClient();
        return $bkrClient->post($url, $request, 'Buckaroo\Shopware6\API\Payload\TransactionResponse');
    }

    public function onOrderTransactionRefunded(OrderStateMachineStateChangeEvent $event)
    {
        return $this->sendTransactionRefund($event, 'refund');
    }

    public function onOrderTransactionRefundedPartially(OrderStateMachineStateChangeEvent $event)
    {
        return $this->sendTransactionRefund($event, 'refund_partially');
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

        return $plugin->getBaseClass() === BuckarooPayment::class;
    }

    /**
     * @param OrderStateMachineStateChangeEvent $event
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrder(OrderStateMachineStateChangeEvent $event): OrderEntity
    {
        $order = $event->getOrder();
        $orderId = $order->getId();
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');
        return $this->orderRepository->search($orderCriteria, $event->getContext())->first();
    }

    private function getCustomFields($order, OrderStateMachineStateChangeEvent $event)
    {
        $transaction = $order->getTransactions()->first();

        $orderTransaction = $this->checkoutHelper->getOrderTransactionById(
            $event->getContext(),
            $transaction->getId()
        );
        $customField = $orderTransaction->getCustomFields() ?? [];

        // $this->logger->error(serialize($transaction));

        // $customField['serviceName'] = $transaction->getPaymentMethod()->getName();
        $customField['serviceName'] =  strtolower(str_replace('PaymentHandler', '', end(explode('\\', $transaction->getPaymentMethod()->getHandlerIdentifier()))));
        $method_path = str_replace('Handlers', 'PaymentMethods', str_replace('PaymentHandler', '', $transaction->getPaymentMethod()->getHandlerIdentifier()));
        $paymentMethod = new $method_path;
        $customField['refund'] = $paymentMethod->canRefund() ? 1 : 0;

        return $customField;
    }

    /**
     * Get the base url
     * When the environment is set live, but the payment is set as test, the test url will be used
     *
     * @return string Base-url
     */
    protected function getBaseUrl($method = ''):string
    {
        return $this->apiHelper->getEnvironment($method) == 'live' ? UrlHelper::LIVE : UrlHelper::TEST;
    }

    /**
     * @return string Full transaction url
     */
    protected function getTransactionUrl($method = ''):string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/Transaction', '/');
    }

}
