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
     * Get the calculated fee for an order
     *
     * @param OrderEntity $order
     * @param string $salesChannelId
     * @param string $paymentCode
     * @return float
     */
    public function getCalculatedFeeForOrder(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        return $this->calculateFee($paymentCode, $order->getAmountTotal(), $salesChannelId);
    }

    public function getOrderTotalWithFee(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        $fee = $this->calculateFee($paymentCode, $order->getAmountTotal(), $salesChannelId);
        $existingFee = $order->getCustomFieldsValue('buckarooFee');
        
        if ($existingFee !== null && is_scalar($existingFee)) {
            $fee = $fee - (float)$existingFee;
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
}
