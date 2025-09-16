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

    public function getFee(string $paymentCode, string $salesChannelId): float
    {
        return $this->asyncPaymentService->settingsService->getBuckarooFee($paymentCode, $salesChannelId);
    }

    public function getOrderTotalWithFee(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        $fee = $this->getFee($paymentCode, $salesChannelId);
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
