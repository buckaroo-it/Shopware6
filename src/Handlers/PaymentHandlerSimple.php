<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

// Conditionally import AbstractPaymentHandler only if it exists
if (class_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')) {
    abstract class PaymentHandlerSimpleBase extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler {}
} else {
    // Fallback for older Shopware versions - create empty base class
    abstract class PaymentHandlerSimpleBase {}
}

/**
 * Simplified Payment Handler using composition instead of class aliases
 * This approach is cleaner and more maintainable than the original class_alias approach
 */
class PaymentHandlerSimple extends PaymentHandlerSimpleBase
{
    private object $handler;
    private string $handlerType;
    
    // Expose services that child payment handlers expect
    protected AsyncPaymentService $asyncPaymentService;
    protected ?FormatRequestParamService $formatRequestParamService = null;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->asyncPaymentService = $asyncPaymentService;
        
        if ($this->isModernHandlerAvailable()) {
            // For modern handler, we'll use the original PaymentHandler with strategy pattern
            // but make it work with AbstractPaymentHandler
            $this->handler = new PaymentHandler($asyncPaymentService);
            $this->handlerType = 'modern';
        } else {
            $this->handler = new PaymentHandlerLegacy($asyncPaymentService);
            $this->handlerType = 'legacy';
            $this->formatRequestParamService = $this->handler->formatRequestParamService ?? null;
        }
    }

    /**
     * Expose a way for specific method handlers to set their Buckaroo class.
     */
    public function setPaymentClass(string $paymentClass): void
    {
        $this->handler->setPaymentClass($paymentClass);
    }

    public function supports(
        mixed $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $this->handler->supports($type, $paymentMethodId, $context);
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        return $this->handler->pay($request, $transaction, $context, $validateStruct);
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $this->handler->finalize($request, $transaction, $context);
    }

    /**
     * Get the type of handler being used
     */
    public function getHandlerType(): string
    {
        return $this->handlerType;
    }

    /**
     * Check if modern handler is available
     */
    public function isModernHandlerAvailable(): bool
    {
        return class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class);
    }

    /**
     * Get the underlying handler instance (for advanced use cases)
     */
    public function getHandler(): object
    {
        return $this->handler;
    }

    /**
     * Magic method to delegate calls to the underlying handler
     * This ensures compatibility with methods expected by child payment handlers
     */
    public function __call(string $name, array $arguments)
    {
        // Check if the method exists in the handler
        if (method_exists($this->handler, $name)) {
            return call_user_func_array([$this->handler, $name], $arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist in " . get_class($this->handler));
    }
}
