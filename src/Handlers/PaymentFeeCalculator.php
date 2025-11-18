<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

class PaymentFeeCalculator
{
    public function __construct(
        private readonly AsyncPaymentService $asyncPaymentService
    ) {
    }

    /**
     * Get the fee amount - returns fixed fee or 0 if percentage
     * For backward compatibility
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     * @return float
     */
    public function getFee(string $paymentCode, string $salesChannelId): float
    {
        return $this->asyncPaymentService->settingsService->getBuckarooFee($paymentCode, $salesChannelId);
    }

    /**
     * Calculate the actual fee based on order total
     * Handles both fixed and percentage fees
     *
     * @param string $paymentCode
     * @param float $orderTotal
     * @param string $salesChannelId
     * @return float
     */
    public function calculateFee(string $paymentCode, float $orderTotal, string $salesChannelId): float
    {
        return $this->asyncPaymentService->settingsService->calculateBuckarooFee(
            $paymentCode,
            $orderTotal,
            $salesChannelId
        );
    }

    /**
     * Calculate the fee for a persisted order, excluding any previously applied fee.
     *
     * @param OrderEntity $order
     * @param string $paymentCode
     * @param string $salesChannelId
     * @return float
     */
    public function calculateFeeForOrder(OrderEntity $order, string $paymentCode, string $salesChannelId): float
    {
        $orderTotal = $order->getAmountTotal();
        $existingFee = $this->getExistingFee($order);

        if ($existingFee > 0) {
            $orderTotal -= $existingFee;
        }

        if ($orderTotal < 0) {
            $orderTotal = 0.0;
        }

        return $this->calculateFee($paymentCode, $orderTotal, $salesChannelId);
    }

    /**
     * Get the calculated fee for an order
     *
     * @param OrderEntity $order
     * @param string $salesChannelId
     * @param string $paymentCode
     * @return float
     */
    public function getCalculatedFeeForOrder(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        return $this->calculateFeeForOrder($order, $paymentCode, $salesChannelId);
    }

    public function getOrderTotalWithFee(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        $existingFee = $this->getExistingFee($order);

        if ($existingFee > 0) {
            return $order->getAmountTotal();
        }

        $fee = $this->calculateFee($paymentCode, $order->getAmountTotal(), $salesChannelId);

        if ($fee === 0.0) {
            return $order->getAmountTotal();
        }

        return $order->getAmountTotal() + $fee;
    }

    public function applyFeeToOrder(
        string $orderId,
        float $fee,
        Context $context
    ): void {
        $this->asyncPaymentService
            ->checkoutHelper
            ->applyFeeToOrder($orderId, $fee, $context);
    }

    /**
     * Retrieve the existing Buckaroo fee stored on the order, if any.
     *
     * @param OrderEntity $order
     * @return float
     */
    private function getExistingFee(OrderEntity $order): float
    {
        $existingFee = $order->getCustomFieldsValue('buckarooFee');

        if ($existingFee !== null && is_numeric($existingFee)) {
            return (float) $existingFee;
        }

        return 0.0;
    }
}
