<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Handlers\Strategy\PaymentHandlerContext;
use Buckaroo\Shopware6\Handlers\Strategy\PaymentHandlerStrategyFactory;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment handler using Strategy pattern for version compatibility
 * Does not extend AbstractPaymentHandler to maintain compatibility with Shopware < 6.7
 */
class PaymentHandler
{
    private PaymentHandlerContext $handlerContext;
    private PaymentHandlerStrategyFactory $strategyFactory;
    protected string $paymentClass = '';

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->strategyFactory = new PaymentHandlerStrategyFactory($asyncPaymentService);
        $strategy = $this->strategyFactory->createStrategy();
        $this->handlerContext = new PaymentHandlerContext($strategy);
        
        // If this instance has a payment class set, transfer it to the strategy
        if (!empty($this->paymentClass)) {
            $this->handlerContext->setPaymentClass($this->paymentClass);
        }
    }

    /**
     * Expose a way for specific method handlers to set their Buckaroo class.
     */
    public function setPaymentClass(string $paymentClass): void
    {
        $this->handlerContext->setPaymentClass($paymentClass);
    }

    public function supports(
        mixed $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $this->handlerContext->supports($type, $paymentMethodId, $context);
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        return $this->handlerContext->pay($request, $transaction, $context, $validateStruct);
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $this->handlerContext->finalize($request, $transaction, $context);
    }

    /**
     * Get the current strategy name being used
     */
    public function getStrategyName(): string
    {
        return $this->handlerContext->getStrategyName();
    }

    /**
     * Check if modern strategy is available
     */
    public function isModernStrategyAvailable(): bool
    {
        return $this->strategyFactory->isModernStrategyAvailable();
    }

    /**
     * Switch strategy (useful for testing or manual override)
     */
    public function switchToStrategy(string $strategyName): void
    {
        $strategy = match ($strategyName) {
            'modern' => $this->strategyFactory->isModernStrategyAvailable()
                ? $this->strategyFactory->createStrategy()
                : throw new \InvalidArgumentException('Modern strategy is not available'),
            'legacy' => $this->strategyFactory->createStrategy(), // Will create legacy if modern not available
            default => throw new \InvalidArgumentException("Unknown strategy: $strategyName")
        };
        
        $this->handlerContext->setStrategy($strategy);
    }
}
