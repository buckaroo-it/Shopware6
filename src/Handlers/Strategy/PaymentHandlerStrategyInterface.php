<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Strategy;

use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface for payment handler strategies
 */
interface PaymentHandlerStrategyInterface
{
    /**
     * Check if this strategy supports the given payment method and context
     */
    public function supports(mixed $type, string $paymentMethodId, Context $context): bool;

    /**
     * Process the payment
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse;

    /**
     * Finalize the payment
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void;

    /**
     * Set the payment class for this strategy
     */
    public function setPaymentClass(string $paymentClass): void;

    /**
     * Get the strategy name/version
     */
    public function getStrategyName(): string;
}
