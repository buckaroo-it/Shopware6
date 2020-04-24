<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Buckaroo\Shopware6\Helpers\Helper;
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

use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Helpers\UrlHelper;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;

use Psr\Log\LoggerInterface;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    /** @var EntityRepositoryInterface */
    private $orderRepository;
    /** @var EntityRepositoryInterface */
    private $orderDeliveryRepository;
    /** @var helper */
    private $helper;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderDeliveryRepository
     * @param helper $helper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderDeliveryRepository,
        Helper $helper,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->helper = $helper;
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

    public function onOrderTransactionRefunded(OrderStateMachineStateChangeEvent $event)
    {
        return true;
    }

    public function onOrderTransactionRefundedPartially(OrderStateMachineStateChangeEvent $event)
    {
        return true;
    }

}
