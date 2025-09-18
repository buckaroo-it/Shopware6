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

// For Shopware 6.5+ implement AsynchronousPaymentHandlerInterface
if (interface_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface')) {
    class_alias('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface', 'Buckaroo\Shopware6\Handlers\PaymentHandlerBaseInterface');
} else {
    // Create a dummy interface for older versions
    interface PaymentHandlerBaseInterface {}
}

/**
 * Simplified Payment Handler using composition instead of class aliases
 * This approach is cleaner and more maintainable than the original class_alias approach
 */
class PaymentHandlerSimple implements PaymentHandlerBaseInterface
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

    // Method for AsynchronousPaymentHandlerInterface (Shopware 6.5+)
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        if (method_exists($this->handler, 'pay') && interface_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface')) {
            return $this->handler->pay($transaction, $dataBag, $salesChannelContext);
        }
        
        // Fallback - should not normally be reached
        throw new \BadMethodCallException('Pay method not available in current Shopware version');
    }

    // Method for AsynchronousPaymentHandlerInterface (Shopware 6.5+)
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        if (method_exists($this->handler, 'finalize') && interface_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface')) {
            $this->handler->finalize($transaction, $request, $salesChannelContext);
            return;
        }
        
        // Fallback - should not normally be reached
        throw new \BadMethodCallException('Finalize method not available in current Shopware version');
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
