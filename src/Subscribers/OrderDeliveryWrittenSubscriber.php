<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Events\OrderStateChangeEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fires the Buckaroo "Capture on shipment" flow when an ERP marks an
 * order delivery as shipped via a direct DAL write (e.g.
 *   PATCH /api/order-delivery/{id}
 *   POST  /api/_action/sync
 * ) that bypasses Shopware's StateMachineRegistry and therefore does not
 * dispatch state_enter.order_delivery.state.shipped.
 *
 * The state-machine action endpoint (POST /api/_action/order_delivery/{id}/state/ship)
 * continues to be handled by {@see OrderStateChangeEvent}. In that path BOTH events
 * fire; deduplication is handled by the customFields['captured'] flag that
 * CaptureService persists synchronously after a successful capture.
 *
 * IMPORTANT: this path is opt-in per payment method, see
 * self::CAPTURE_METHODS_ON_DAL_WRITE. It is deliberately NOT enabled for Klarna KP.
 * A Klarna KP capture is a "Pay on reservation" call, and the reservation can already
 * have been captured outside of Shopware (Buckaroo KlarnaKP AutoPay, a manual capture
 * in the Payment Plaza, or a capture done by an older plugin version). In those cases
 * customFields['captured'] is absent, so the canCapture* guards cannot deduplicate:
 * every delivery write would retry the Pay and Buckaroo answers 491 "Pay on
 * reservation ... is not possible: reservation has status FullyCaptured". That
 * validation failure is pushed back to PushController and can flip an already
 * refunded transaction to cancelled. Klarna KP therefore keeps the single-shot
 * state_enter path only.
 */
class OrderDeliveryWrittenSubscriber implements EventSubscriberInterface
{
    /**
     * Buckaroo payment methods (lowercase `brqPaymentMethod`) for which a direct DAL
     * write of the shipped state may trigger capture-on-shipment. Klarna MoR only: its
     * capture is guarded by customFields['captured'] and ['dataRequestKey'], both of
     * which the plugin itself always writes, so repeated writes are idempotent.
     *
     * @var array<int, string>
     */
    public const CAPTURE_METHODS_ON_DAL_WRITE = ['klarna'];

    private OrderStateChangeEvent $orderStateChangeEvent;

    private EntityRepository $orderDeliveryRepository;

    private EntityRepository $stateMachineStateRepository;

    private LoggerInterface $logger;

    /**
     * Cached map of state-machine-state UUID => technical name, scoped to the
     * order_delivery.state state machine. Populated lazily on first lookup.
     *
     * @var array<string, string>|null
     */
    private ?array $orderDeliveryStateNameById = null;

    public function __construct(
        OrderStateChangeEvent $orderStateChangeEvent,
        EntityRepository $orderDeliveryRepository,
        EntityRepository $stateMachineStateRepository,
        LoggerInterface $logger
    ) {
        $this->orderStateChangeEvent = $orderStateChangeEvent;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderDeliveryDefinition::ENTITY_NAME . '.written' => 'onOrderDeliveryWritten',
        ];
    }

    public function onOrderDeliveryWritten(EntityWrittenEvent $event): void
    {
        try {
            $context = $event->getContext();
            $shippedDeliveryIds = [];

            foreach ($event->getWriteResults() as $writeResult) {
                $payload = $writeResult->getPayload();

                if (!is_array($payload) || !isset($payload['stateId'])) {
                    continue;
                }

                $newStateId = $payload['stateId'];
                if (!is_string($newStateId) || $newStateId === '') {
                    continue;
                }

                if (!$this->isShippedState($newStateId, $context)) {
                    continue;
                }

                $primaryKey = $writeResult->getPrimaryKey();
                if (is_string($primaryKey) && $primaryKey !== '') {
                    $shippedDeliveryIds[$primaryKey] = $primaryKey;
                }
            }

            if ($shippedDeliveryIds === []) {
                return;
            }

            $this->triggerCaptureForDeliveries(array_values($shippedDeliveryIds), $context);
        } catch (\Throwable $th) {
            // Never let a capture-trigger failure roll back the merchant's
            // delivery write. Log and swallow.
            $this->logger->error(
                'Buckaroo OrderDeliveryWrittenSubscriber failed: ' . $th->getMessage(),
                ['exception' => $th]
            );
        }
    }

    /**
     * @param list<string> $deliveryIds
     */
    private function triggerCaptureForDeliveries(array $deliveryIds, Context $context): void
    {
        $criteria = new Criteria($deliveryIds);
        $criteria->addAssociation('order');

        $deliveries = $this->orderDeliveryRepository->search($criteria, $context);

        foreach ($deliveries as $delivery) {
            if (!$delivery instanceof OrderDeliveryEntity) {
                continue;
            }

            $order = $delivery->getOrder();
            if ($order === null) {
                $this->logger->debug(
                    'Skipping Buckaroo capture-on-shipment trigger: no parent order on delivery',
                    ['deliveryId' => $delivery->getId()]
                );
                continue;
            }

            $this->orderStateChangeEvent->triggerCaptureForShippedOrder(
                $order->getId(),
                $order->getSalesChannelId(),
                $context,
                self::CAPTURE_METHODS_ON_DAL_WRITE
            );
        }
    }

    private function isShippedState(string $stateId, Context $context): bool
    {
        if ($this->orderDeliveryStateNameById === null) {
            $this->loadOrderDeliveryStateMap($context);
        }

        if (!isset($this->orderDeliveryStateNameById[$stateId])) {
            // Unknown state id, try a one-off lookup (covers custom states that
            // weren't part of the initial state machine snapshot).
            $this->orderDeliveryStateNameById[$stateId] = $this->fetchStateTechnicalName($stateId, $context);
        }

        return $this->orderDeliveryStateNameById[$stateId] === OrderDeliveryStates::STATE_SHIPPED;
    }

    private function loadOrderDeliveryStateMap(Context $context): void
    {
        $this->orderDeliveryStateNameById = [];

        $criteria = new Criteria();
        $criteria->addAssociation('stateMachine');

        $states = $this->stateMachineStateRepository->search($criteria, $context);

        foreach ($states as $state) {
            if (!$state instanceof StateMachineStateEntity) {
                continue;
            }

            $stateMachine = $state->getStateMachine();
            if ($stateMachine === null
                || $stateMachine->getTechnicalName() !== OrderDeliveryStates::STATE_MACHINE
            ) {
                continue;
            }

            $this->orderDeliveryStateNameById[$state->getId()] = $state->getTechnicalName();
        }
    }

    private function fetchStateTechnicalName(string $stateId, Context $context): string
    {
        $criteria = new Criteria([$stateId]);
        $criteria->addAssociation('stateMachine');

        $state = $this->stateMachineStateRepository->search($criteria, $context)->first();

        if (!$state instanceof StateMachineStateEntity) {
            return '';
        }

        $stateMachine = $state->getStateMachine();
        if ($stateMachine === null
            || $stateMachine->getTechnicalName() !== OrderDeliveryStates::STATE_MACHINE
        ) {
            return '';
        }

        return $state->getTechnicalName();
    }
}
