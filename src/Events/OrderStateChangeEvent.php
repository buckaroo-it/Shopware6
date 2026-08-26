<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\KlarnaMorService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\NotificationServiceFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    protected TransactionService $transactionService;

    protected InvoiceService $invoiceService;

    protected SettingsService $settingsService;

    protected OrderService $orderService;

    protected CaptureService $captureService;

    protected KlarnaMorService $klarnaMorService;

    protected StateTransitionService $stateTransitionService;

    protected object $notificationService; // Can be either NotificationService

    /** @var LoggerInterface */
    protected $logger;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     */
    public function __construct(
        TransactionService $transactionService,
        InvoiceService $invoiceService,
        SettingsService $settingsService,
        OrderService $orderService,
        LoggerInterface $logger,
        CaptureService $captureService,
        NotificationServiceFactory $notificationServiceFactory,
        KlarnaMorService $klarnaMorService,
        StateTransitionService $stateTransitionService
    ) {
        $this->transactionService = $transactionService;
        $this->invoiceService = $invoiceService;
        $this->settingsService = $settingsService;
        $this->orderService = $orderService;
        $this->logger = $logger;
        $this->captureService = $captureService;
        $this->notificationService = $notificationServiceFactory->getNotificationService();
        $this->klarnaMorService = $klarnaMorService;
        $this->stateTransitionService = $stateTransitionService;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_delivery.state.shipped' => 'onOrderDeliveryStateShipped',
            'state_enter.order.state.cancelled' => 'onOrderStateCancelled',
            StateMachineTransitionEvent::class => 'onStateMachineTransition',
        ];
    }

    /**
     * Fallback path: StateMachineRegistry dispatches this class-based event for
     * every state transition, independently of the `state_enter.*` business
     * events. Deduplication with onOrderStateCancelled is handled by the
     * `reservationCancelled` custom field and the transaction-state guards.
     */
    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== OrderDefinition::ENTITY_NAME) {
            return;
        }

        if ($event->getToPlace()->getTechnicalName() !== OrderStates::STATE_CANCELLED) {
            return;
        }

        $this->logger->info('Buckaroo Klarna MoR: order cancelled transition received (state machine)', [
            'orderId' => $event->getEntityId(),
        ]);

        try {
            $this->cancelKlarnaMorReservation(
                $event->getEntityId(),
                $event->getContext()
            );
        } catch (\Throwable $th) {
            // Never let a Buckaroo failure break the merchant's order-cancellation flow.
            $this->logger->error(__METHOD__ . ' ' . (string)$th);
        }
    }

    /**
     * When an order is cancelled from the Shopware Administration (or any other
     * state-machine transition into `cancelled`), release the Klarna MoR
     * authorization in Buckaroo if the payment transaction is still authorized.
     */
    public function onOrderStateCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $this->logger->info('Buckaroo Klarna MoR: order cancelled event received', [
            'orderId' => $event->getOrder()->getId(),
        ]);

        try {
            $this->cancelKlarnaMorReservation(
                $event->getOrder()->getId(),
                $event->getContext()
            );
        } catch (\Throwable $th) {
            // Never let a Buckaroo failure break the merchant's order-cancellation flow.
            $this->logger->error(__METHOD__ . ' ' . (string)$th);
        }
    }

    /**
     * Send a Klarna MoR CancelReservation datarequest to Buckaroo for an
     * eligible cancelled order and synchronize the Shopware payment state.
     */
    public function cancelKlarnaMorReservation(string $orderId, Context $context): void
    {
        $order = $this->orderService->getOrderById(
            $orderId,
            [
                'transactions',
                'transactions.paymentMethod',
                'transactions.paymentMethod.plugin',
                'transactions.stateMachineState',
                'deliveries',
                'deliveries.stateMachineState',
                'salesChannel',
                'currency'
            ],
            $context
        );

        if ($order === null) {
            $this->logger->debug(__METHOD__ . ' Cannot find order entity', ['orderId' => $orderId]);
            return;
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);

        if (!$this->canCancelKlarnaMorReservation($order, $customFields)) {
            return;
        }

        $this->logger->info('Buckaroo Klarna MoR: sending CancelReservation datarequest', [
            'orderId'        => $order->getId(),
            'orderNumber'    => $order->getOrderNumber(),
            'dataRequestKey' => $customFields['dataRequestKey'],
        ]);

        $result = $this->klarnaMorService->execute(
            Request::createFromGlobals(),
            $order,
            $context,
            KlarnaMorService::ACTION_CANCEL_RESERVATION
        );

        if (isset($result['status']) && $result['status'] === true) {
            $this->logger->info('Buckaroo Klarna MoR: reservation cancelled successfully', [
                'orderId'     => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
            ]);

            $orderTransactionId = $this->transactionService->getLastTransactionId($order);
            if ($orderTransactionId !== null) {
                $this->stateTransitionService->transitionPaymentState(
                    'cancelled',
                    $orderTransactionId,
                    $context
                );
                $this->transactionService->saveTransactionData(
                    $orderTransactionId,
                    $context,
                    ['reservationCancelled' => true]
                );
            }
        } else {
            $this->logger->error('Buckaroo Klarna MoR: CancelReservation datarequest failed', [
                'orderId'     => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'message'     => $result['message'] ?? 'Unknown error',
                'code'        => $result['code'] ?? null,
            ]);
        }

        $this->createNotifications($result, $context);
    }

    /**
     * Eligibility guards: Klarna MoR payment, authorization still active,
     * not captured, not already cancelled/released, order not shipped.
     *
     * @param array<mixed> $customFields
     */
    private function canCancelKlarnaMorReservation(OrderEntity $order, array $customFields): bool
    {
        if (
            !isset($customFields['brqPaymentMethod']) ||
            !is_string($customFields['brqPaymentMethod']) ||
            strtolower($customFields['brqPaymentMethod']) !== 'klarna'
        ) {
            return false;
        }

        if (
            !isset($customFields['dataRequestKey']) ||
            !is_string($customFields['dataRequestKey']) ||
            $customFields['dataRequestKey'] === ''
        ) {
            $this->logger->debug('Buckaroo Klarna MoR: skipping cancellation, missing dataRequestKey', [
                'orderId' => $order->getId(),
            ]);
            return false;
        }

        if (isset($customFields['captured'])) {
            $this->logger->info('Buckaroo Klarna MoR: skipping cancellation, payment already captured', [
                'orderId' => $order->getId(),
            ]);
            return false;
        }

        if (isset($customFields['reservationCancelled']) && $customFields['reservationCancelled'] === true) {
            $this->logger->debug('Buckaroo Klarna MoR: skipping cancellation, reservation already cancelled', [
                'orderId' => $order->getId(),
            ]);
            return false;
        }

        // Eligible while the Buckaroo authorization is still active. The Shopware
        // transaction is either still `authorized`, or already `cancelled` locally
        // (the admin cancel dialog cancels the payment together with the order,
        // and that local transition does not release anything at Buckaroo).
        // Captured/paid/refunded transactions are excluded.
        $transactionState = $this->getLastTransactionState($order);
        if (
            !in_array(
                $transactionState,
                [OrderTransactionStates::STATE_AUTHORIZED, OrderTransactionStates::STATE_CANCELLED],
                true
            )
        ) {
            $this->logger->info(
                'Buckaroo Klarna MoR: skipping cancellation, payment transaction is not authorized',
                [
                    'orderId'          => $order->getId(),
                    'transactionState' => $transactionState,
                ]
            );
            return false;
        }

        if ($this->isShipped($order)) {
            $this->logger->info('Buckaroo Klarna MoR: skipping cancellation, order already shipped', [
                'orderId' => $order->getId(),
            ]);
            return false;
        }

        return true;
    }

    private function getLastTransactionState(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null) {
            return null;
        }

        $transaction = $transactions->last();
        if ($transaction === null) {
            return null;
        }

        $state = $transaction->getStateMachineState();

        return $state !== null ? $state->getTechnicalName() : null;
    }

    private function isShipped(OrderEntity $order): bool
    {
        $deliveries = $order->getDeliveries();
        if ($deliveries === null) {
            return false;
        }

        foreach ($deliveries as $delivery) {
            $state = $delivery->getStateMachineState();
            if (
                $state !== null &&
                in_array(
                    $state->getTechnicalName(),
                    [OrderDeliveryStates::STATE_SHIPPED, OrderDeliveryStates::STATE_PARTIALLY_SHIPPED],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function onOrderDeliveryStateShipped(OrderStateMachineStateChangeEvent $event): bool
    {
        return $this->triggerCaptureForShippedOrder(
            $event->getOrder()->getId(),
            $event->getSalesChannelId(),
            $event->getContext()
        );
    }

    /**
     * Reusable capture-on-shipment trigger. Called by:
     *  - onOrderDeliveryStateShipped (state-machine path via state_enter event)
     *  - OrderDeliveryWrittenSubscriber (direct DAL write path via order_delivery.written)
     *
     * Deduplication between the two paths is handled by two transaction custom fields,
     * both checked by the canCapture* guards and by CaptureService::validate():
     *  - customFields['captured']: a confirmed capture (synchronous success response,
     *    or the success push for an asynchronously processed capture);
     *  - customFields[CaptureService::CAPTURE_INITIATED]: an in-flight marker persisted
     *    BEFORE the capture request is handed to Buckaroo, covering captures the engine
     *    processes asynchronously (e.g. Klarna MoR Pay, 791 Pending processing) where
     *    `captured` cannot be set from the synchronous response.
     *
     * @param array<int, string>|null $onlyPaymentMethods When given, the trigger is
     *        restricted to these Buckaroo payment methods (lowercase `brqPaymentMethod`
     *        values). The direct DAL write path uses this to opt in one method at a time
     *        instead of every capture-on-shipment method; see
     *        OrderDeliveryWrittenSubscriber::CAPTURE_METHODS_ON_DAL_WRITE.
     */
    public function triggerCaptureForShippedOrder(
        string $orderId,
        ?string $salesChannelId,
        Context $context,
        ?array $onlyPaymentMethods = null
    ): bool {
        $order = $this->orderService->getOrderById(
            $orderId,
            [
                'transactions',
                'transactions.paymentMethod',
                'transactions.paymentMethod.plugin',
                'salesChannel',
                'currency'
            ],
            $context
        );

        if ($order === null) {
            $this->logger->debug("Cannot find order entity");
            return false;
        }
        $customFields = $this->transactionService->getCustomFields($order, $context);

        if (!isset($customFields['brqPaymentMethod'])) {
            return false;
        }

        if (!is_string($customFields['brqPaymentMethod'])) {
            $this->logger->warning('Invalid brqPaymentMethod type detected', [
                'type' => gettype($customFields['brqPaymentMethod']),
                'value' => $customFields['brqPaymentMethod']
            ]);
            return false;
        }

        if (
            $onlyPaymentMethods !== null &&
            !in_array(strtolower($customFields['brqPaymentMethod']), $onlyPaymentMethods, true)
        ) {
            $this->logger->debug(
                'Buckaroo capture-on-shipment: payment method not enabled for this trigger path',
                [
                    'orderId'          => $orderId,
                    'brqPaymentMethod' => $customFields['brqPaymentMethod'],
                    'allowedMethods'   => $onlyPaymentMethods,
                ]
            );
            return false;
        }

        if (
            $this->canCaptureAfterpay(
                $customFields,
                $order->getCustomFields(),
                $salesChannelId
            )
        ) {
            $this->capture($order, $context);
        }

        if ($this->canCaptureKlarna($customFields, $salesChannelId)) {
            $this->capture($order, $context);
        }

        if ($this->canCaptureKlarnaKp($customFields, $salesChannelId)) {
            $this->capture($order, $context);
        }

        return true;
    }

    private function canCaptureAfterpay(
        array $customFields,
        ?array $orderCustomFields,
        ?string $salesChannelId
    ): bool {
        // Validate payment method exists and is string type
        if (!isset($customFields['brqPaymentMethod']) || !is_string($customFields['brqPaymentMethod'])) {
            return false;
        }
        
        // Guard against null sales channel ID - cannot determine capture settings without it
        if ($salesChannelId === null) {
            $this->logger->warning('Cannot determine afterpay capture settings: sales channel ID is null');
            return false;
        }
        
        return $customFields['brqPaymentMethod'] === 'afterpay' &&
            !isset($customFields['captured']) &&
            !CaptureService::isCaptureInFlight($customFields) &&
            $this->settingsService->getSetting('afterpayCaptureonshippent', $salesChannelId) &&
            isset($orderCustomFields[CaptureService::ORDER_IS_AUTHORIZED]) &&
            $orderCustomFields[CaptureService::ORDER_IS_AUTHORIZED] === true;
    }

    private function canCaptureKlarna(array $customFields, ?string $salesChannelId): bool
    {
        if (!isset($customFields['brqPaymentMethod']) || !is_string($customFields['brqPaymentMethod'])) {
            return false;
        }

        if ($salesChannelId === null) {
            $this->logger->warning('Cannot determine Klarna capture settings: sales channel ID is null');
            return false;
        }

        // Klarna MoR: capture-on-shipment is mandatory, but only ONE Pay may be sent
        // per reservation. `captured` records a confirmed capture (synchronous success
        // or the success push), the in-flight marker covers the window in which the
        // engine is still processing an earlier Pay (791 Pending) — during a single
        // ship action both the order_delivery.written and the
        // state_enter.order_delivery.state.shipped paths fire this guard.
        return $customFields['brqPaymentMethod'] === 'klarna'
            && !isset($customFields['captured'])
            && !CaptureService::isCaptureInFlight($customFields)
            && isset($customFields['dataRequestKey'])
            && (bool)$this->settingsService->getSetting('klarnaCaptureonshipment', $salesChannelId);
    }

    private function canCaptureKlarnaKp(array $customFields, ?string $salesChannelId): bool
    {
        if (!isset($customFields['brqPaymentMethod']) || !is_string($customFields['brqPaymentMethod'])) {
            return false;
        }

        if ($salesChannelId === null) {
            $this->logger->warning('Cannot determine Klarna KP capture settings: sales channel ID is null');
            return false;
        }

        return strtolower($customFields['brqPaymentMethod']) === 'klarnakp'
            && !isset($customFields['captured'])
            && !CaptureService::isCaptureInFlight($customFields)
            && isset($customFields['reservationNumber'])
            && (bool)$this->settingsService->getSetting('klarnakpCaptureonshipment', $salesChannelId);
    }

    private function capture(OrderEntity $order, Context $context): void
    {
        try {
            $this->createNotifications(
                $this->captureService->capture(
                    Request::createFromGlobals(),
                    $order,
                    $context
                ),
                $context
            );
        } catch (\Throwable $th) {
            $this->logger->error(__METHOD__ . (string)$th);
        }
    }

    private function createNotifications(?array $result, Context $context): void
    {
        if ($result === null) {
            return;
        }
        $status = 'warning';
        if (isset($result['status']) && $result['status'] === true) {
            $status = 'success';
        }

        $message = "A error has occurred while processing the buckaroo capture";
        if (isset($result['message'])) {
            $message = $result['message'];
        }

        if (is_object($this->notificationService) && method_exists($this->notificationService, 'createNotification')) {
            $this->notificationService->createNotification(
                [
                'id' => Uuid::randomHex(),
                'status' => $status,
                'message' => $message,
                'adminOnly' => true,
                'requiredPrivileges' => [],
                'createdByIntegrationId' => null,
                'createdByUserId' => null,
                ],
                $context
            );
        }
    }
}
