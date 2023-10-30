<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;

class ChangeStateService
{
    public const PAYMENT_STATE_CHANGED = 'payment_state_changed';
    public const PAYMENT_STATE_NOT_CHANGED = 'payment_state_not_changed';
    public const PAYMENT_STATE_FAILED_TO_CHANGE = 'payment_state_failed_to_change';

    private StateMachineRegistry $stateMachineRegistry;

    private EntityRepository $orderTransactionRepository;

    public function __construct(
        EntityRepository $orderTransactionRepository,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }


    /**
     * Change state
     *
     * @param string $orderTransactionId
     * @param string $newState
     * @param Context $context
     *
     * @return string
     */
    public function setState(
        string $orderTransactionId,
        string $newState,
        Context $context
    ): string {

        $orderTransaction = $this->getOrderTransaction($orderTransactionId, $context);
        if (
            $orderTransaction === null ||
            $orderTransaction->getStateMachineState() === null ||
            $orderTransaction->getStateMachineState()->getTechnicalName() === $newState
        ) {
            return self::PAYMENT_STATE_NOT_CHANGED;
        }
        $availableTransitions = $this->getAvailableTransitions(
            $orderTransaction->getId(),
            $context
        );

        $transition = $this->getTransition($availableTransitions, $newState);
        if ($transition === null) {
            return self::PAYMENT_STATE_FAILED_TO_CHANGE;
        }

        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                $transition->getActionName(),
                'stateId'
            ),
            $context
        );

        return self::PAYMENT_STATE_CHANGED;
    }

    /**
     * @param string $transactionId
     * @param Context $context
     *
     * @return array<StateMachineTransitionEntity>
     */
    private function getAvailableTransitions(string $transactionId, Context $context): array
    {
        return $this->stateMachineRegistry->getAvailableTransitions(
            OrderTransactionDefinition::ENTITY_NAME,
            $transactionId,
            'stateId',
            $context
        );
    }

    /**
     * @param array<StateMachineTransitionEntity> $availableTransitions
     * @param string $state
     *
     * @return StateMachineTransitionEntity|null
     */
    private function getTransition(array $availableTransitions, string $state): ?StateMachineTransitionEntity
    {
        foreach ($availableTransitions as $transition) {
            if (
                $transition->getToStateMachineState() !== null &&
                $transition->getToStateMachineState()->getTechnicalName() === $state
            ) {
                return $transition;
            }
        }
        return null;
    }

    /**
     * Get fresh orderTransaction
     *
     * @param string $orderTransactionId
     * @param Context $context
     *
     * @return OrderTransactionEntity|null
     */
    private function getOrderTransaction(string $orderTransactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociations([
            'stateMachine',
        ]);

        /** @var OrderTransactionEntity|null */
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }
}
