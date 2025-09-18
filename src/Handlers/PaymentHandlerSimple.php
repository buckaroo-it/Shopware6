<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

// Version-compatible base class determination
if (class_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')) {
    // Shopware 6.7+: Extend AbstractPaymentHandler for unified registry
    abstract class PaymentHandlerSimpleBase extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler {}
} else {
    // Shopware 6.5 and older: Use basic class that can implement interfaces
    abstract class PaymentHandlerSimpleBase {}
}

/**
 * Simplified Payment Handler using composition with version compatibility
 * - Shopware 6.7+: Extends AbstractPaymentHandler for unified registry
 * - Shopware 6.5: Implements AsynchronousPaymentHandlerInterface dynamically
 * - Older versions: Basic composition pattern
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
        
        // Transfer payment class from child class to underlying handler if it exists
        if (property_exists($this, 'paymentClass')) {
            $reflection = new \ReflectionClass($this);
            if ($reflection->hasProperty('paymentClass')) {
                $property = $reflection->getProperty('paymentClass');
                if ($property->isInitialized($this)) {
                    $paymentClass = $property->getValue($this);
                    if (!empty($paymentClass)) {
                        $this->handler->setPaymentClass($paymentClass);
                    }
                }
            }
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

    // Implementation for AbstractPaymentHandler (Shopware 6.7+)
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        if (method_exists($this->handler, 'pay')) {
            // Check if handler supports new signature (6.7+)
            $reflection = new \ReflectionMethod($this->handler, 'pay');
            $parameters = $reflection->getParameters();
            
            if (count($parameters) >= 4) {
                // New signature (AbstractPaymentHandler)
                return $this->handler->pay($request, $transaction, $context, $validateStruct);
            }
        }
        
        // Fallback - should not normally be reached in 6.7+
        throw new \BadMethodCallException('Pay method not available in current Shopware version');
    }

    // Implementation for AbstractPaymentHandler (Shopware 6.7+)
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        if (method_exists($this->handler, 'finalize')) {
            $this->handler->finalize($request, $transaction, $context);
            return;
        }
        
        // Fallback - should not normally be reached
        throw new \BadMethodCallException('Finalize method not available in current Shopware version');
    }

    // Legacy method for AsynchronousPaymentHandlerInterface (Shopware 6.5)
    // This will only be called in 6.5 when not extending AbstractPaymentHandler
    public function payAsync(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        if (method_exists($this->handler, 'pay')) {
            return $this->handler->pay($transaction, $dataBag, $salesChannelContext);
        }
        
        throw new \BadMethodCallException('Pay method not available');
    }

    // Legacy method for AsynchronousPaymentHandlerInterface (Shopware 6.5)  
    // This will only be called in 6.5 when not extending AbstractPaymentHandler
    public function finalizeAsync(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        if (method_exists($this->handler, 'finalize')) {
            $this->handler->finalize($transaction, $request, $salesChannelContext);
            return;
        }
        
        throw new \BadMethodCallException('Finalize method not available');
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
        // Handle dynamic interface methods for Shopware 6.5 compatibility
        if ($name === 'pay' && count($arguments) === 3 && !$this->isModernHandlerAvailable()) {
            // AsynchronousPaymentHandlerInterface::pay signature
            return $this->handler->pay($arguments[0], $arguments[1], $arguments[2]);
        }
        
        if ($name === 'finalize' && count($arguments) === 3 && !$this->isModernHandlerAvailable()) {
            // AsynchronousPaymentHandlerInterface::finalize signature  
            $this->handler->finalize($arguments[0], $arguments[1], $arguments[2]);
            return;
        }
        
        // Check if the method exists in the handler
        if (method_exists($this->handler, $name)) {
            return call_user_func_array([$this->handler, $name], $arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist in " . get_class($this->handler));
    }
}
