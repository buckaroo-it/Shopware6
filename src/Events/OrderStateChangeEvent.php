<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    protected TransactionService $transactionService;

    protected InvoiceService $invoiceService;

    protected SettingsService $settingsService;

    protected OrderService $orderService;

    protected CaptureService $captureService;

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
        CaptureService $captureService
    ) {
        $this->transactionService = $transactionService;
        $this->invoiceService = $invoiceService;
        $this->settingsService = $settingsService;
        $this->orderService = $orderService;
        $this->logger = $logger;
        $this->captureService = $captureService;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_delivery.state.shipped' => 'onOrderDeliveryStateShipped',
        ];
    }

    public function onOrderDeliveryStateShipped(OrderStateMachineStateChangeEvent $event): bool
    {
        $context = $event->getContext();
        $eventOrder = $event->getOrder();
        $order = $this->orderService->getOrderById(
            $eventOrder->getId(),
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
        $salesChannelId =  $event->getSalesChannelId();

        if (!isset($customFields['brqPaymentMethod'])) {
            return false;
        }
        if (
            $customFields['brqPaymentMethod'] == 'Billink' &&
            $this->settingsService->getSetting('BillinkMode', $salesChannelId) == 'authorize' &&
            $this->settingsService->getSetting('BillinkCreateInvoiceAfterShipment', $salesChannelId)
        ) {
            $this->invoiceService->generateInvoice($eventOrder, $context, $salesChannelId);
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
        return true;
    }

    private function canCaptureAfterpay(
        array $customFields,
        array $orderCustomFields,
        string $salesChannelId
    ): bool {
        return isset($customFields['brqPaymentMethod']) &&
            $customFields['brqPaymentMethod'] == 'afterpay' &&
            $this->settingsService->getSetting('afterpayCaptureonshippent', $salesChannelId) &&
            isset($orderCustomFields[CaptureService::ORDER_IS_AUTHORIZED]) &&
            $orderCustomFields[CaptureService::ORDER_IS_AUTHORIZED] === true;
    }

    private function capture(OrderEntity $order, Context $context): void
    {
        try {
            $this->captureService->capture(Request::createFromGlobals(), $order, $context);
        } catch (\Throwable $th) {
            $this->logger->error(__METHOD__ . (string)$th);
        }
    }
}
