<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;

class StateTransitionService
{
    protected TransactionService $transactionService;

    protected OrderTransactionStateHandler $orderTransactionStateHandler;

    protected StateMachineStateEntity $stateMachineStateEntity;

    protected StateMachineRegistry $stateMachineRegistry;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected EntityRepository $stateMachineRepository;

    public function __construct(
        TransactionService $transactionService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $stateMachineRepository,
        StateMachineRegistry $stateMachineRegistry,
        LoggerInterface $logger
    ) {
        $this->transactionService = $transactionService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
    }

    /**
     * @param string $status
     * @param string $orderTransactionId
     * @param Context $context
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
    public function getCorrectTransitionAction(string $status): ?string
    {
        switch ($status) {
            case 'completed':
            case 'paid':
                return StateMachineTransitionActions::ACTION_PAID;
            case 'pay_partially':
                return StateMachineTransitionActions::ACTION_PAID_PARTIALLY;
            case 'declined':
            case 'cancelled':
            case 'void':
            case 'expired':
                return StateMachineTransitionActions::ACTION_CANCEL;
            case 'fail':
                return StateMachineTransitionActions::ACTION_FAIL;
            case 'refunded':
                return StateMachineTransitionActions::ACTION_REFUND;
            case 'partial_refunded':
                return StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;
            case 'initialized':
            case 'open':
                return StateMachineTransitionActions::ACTION_REOPEN;
            case 'process':
                return StateMachineTransitionActions::ACTION_PROCESS;
            case 'authorize':
                return StateMachineTransitionActions::ACTION_AUTHORIZE;
        }
        return null;
    }

    /**
     * @param string $actionName
     * @param string $orderTransactionId
     * @param Context $context
     * @return bool
     */
    public function isSameState(string $actionName, string $orderTransactionId, Context $context): bool
    {
        $transaction = $this->transactionService->getOrderTransaction($orderTransactionId, $context);
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);

        if ($transaction === null) {
            return false;
        }

        $stateMachine = $transaction->getStateMachineState();
        return $stateMachine !== null && $stateMachine->getTechnicalName() == $stateName;
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
        $functionName = lcfirst($string);

        if ($functionName === 'paidPartially') {
            $functionName = 'payPartially';
        }

        return $functionName;
    }

    /**
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): ?string
    {
        switch ($actionName) {
            case StateMachineTransitionActions::ACTION_PAID:
                return OrderTransactionStates::STATE_PAID;
            case StateMachineTransitionActions::ACTION_PAID_PARTIALLY:
                return OrderTransactionStates::STATE_PARTIALLY_PAID;
            case StateMachineTransitionActions::ACTION_CANCEL:
                return OrderTransactionStates::STATE_CANCELLED;
            case StateMachineTransitionActions::ACTION_REFUND:
                return OrderTransactionStates::STATE_REFUNDED;
            case StateMachineTransitionActions::ACTION_REFUND_PARTIALLY:
                return OrderTransactionStates::STATE_PARTIALLY_REFUNDED;
        }
        return null;
    }
    /**
     *
     * @param array<mixed> $statuses
     * @param string $orderTransactionId
     * @param Context $context
     *
     * @return boolean
     */
    public function isTransitionPaymentState(array $statuses, string $orderTransactionId, Context $context): bool
    {
        foreach ($statuses as $status) {
            if (!is_string($status)) {
                continue;
            }
            $transitionAction = $this->getCorrectTransitionAction($status);

            if ($transitionAction === null) {
                continue;
            }
            $transaction = $this->transactionService->getOrderTransaction($orderTransactionId, $context);

            if ($transaction === null) {
                continue;
            }

            $actionStatusTransition = $this->getTransitionFromActionName($transitionAction, $context);
            if (
                $actionStatusTransition !== null &&
                $transaction->getStateId() == $actionStatusTransition->getId()
            ) {
                return true;
            }
        }
        return false;
    }
    /**
     * @param string $actionName
     * @param Context $context
     * @return StateMachineStateEntity|null
     */
    public function getTransitionFromActionName(string $actionName, Context $context): ?StateMachineStateEntity
    {
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);
        $criteria  = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $stateName));

        /** @var \Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity|null */
        return $this->stateMachineRepository->search($criteria, $context)->first();
    }

    public function changeOrderStatus(OrderEntity $order, Context $context, string $transitionName): void
    {
        if ($this->isOrderState($order, [$transitionName])) {
            return;
        }

        if (!empty($transitionName)) {
            try {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $order->getId(),
                        $transitionName,
                        'stateId'
                    ),
                    $context
                );
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage(), [$e]);
            }
        }

        return;
    }

    public function changeDeliveryStatus(OrderEntity $order, Context $context, string $transitionName): void
    {
        $deliveries = $order->getDeliveries();

        if (!empty($transitionName) && ($deliveries instanceof OrderDeliveryCollection && $deliveries->count() > 0)) {
            $delivery = $deliveries->first();

            if ($delivery instanceof OrderDeliveryEntity) {
                $deliveryId = $delivery->getId();

                try {
                    $this->stateMachineRegistry->transition(
                        new Transition(
                            OrderDeliveryDefinition::ENTITY_NAME,
                            $deliveryId,
                            $transitionName,
                            'stateId'
                        ),
                        $context
                    );
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage(), [$e]);
                }
            }
        }

        return;
    }
    /**
     *
     * @param OrderEntity $order
     * @param array<mixed> $statuses
     *
     * @return boolean
     */
    public function isOrderState(OrderEntity $order, array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (!is_string($status)) {
                continue;
            }

            $stateName = $this->getOrderTransactionStatesNameFromAction($status);

            $stateMachine = $order->getStateMachineState();

            if (
                $stateMachine !== null &&
                $stateMachine->getTechnicalName() == $stateName
            ) {
                return true;
            }
        }
        return false;
    }

    public function isOrderPaid(OrderEntity $order): bool
    {
        $transactions = $order->getTransactions();

        if (!$transactions instanceof OrderTransactionCollection || $transactions->count() === 0) {
            return false;
        }

        $paymentState = $transactions->last()?->getStateMachineState()?->getTechnicalName();

        return in_array($paymentState, ['paid', 'pay_partially'], true);
    }
}
