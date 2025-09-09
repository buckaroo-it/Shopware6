<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Strategy;

use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment handler context that uses strategy pattern
 */
class PaymentHandlerContext
{
    private PaymentHandlerStrategyInterface $strategy;

    public function __construct(PaymentHandlerStrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Set a different strategy
     */
    public function setStrategy(PaymentHandlerStrategyInterface $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Get current strategy
     */
    public function getStrategy(): PaymentHandlerStrategyInterface
    {
        return $this->strategy;
    }

    /**
     * Check if this handler supports the given payment method and context
     */
    public function supports(mixed $type, string $paymentMethodId, Context $context): bool
    {
        return $this->strategy->supports($type, $paymentMethodId, $context);
    }

    /**
     * Process the payment using the current strategy
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        return $this->strategy->pay($request, $transaction, $context, $validateStruct);
    }

    /**
     * Finalize the payment using the current strategy
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $this->strategy->finalize($request, $transaction, $context);
    }

    /**
     * Set the payment class for the current strategy
     */
    public function setPaymentClass(string $paymentClass): void
    {
        $this->strategy->setPaymentClass($paymentClass);
    }

    /**
     * Get the name of the current strategy
     */
    public function getStrategyName(): string
    {
        return $this->strategy->getStrategyName();
    }
}
