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

// Create version-specific wrapper classes instead of trying to implement both interfaces
if (class_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')) {
    // Shopware 6.7+: Create a class that extends AbstractPaymentHandler
    abstract class PaymentHandlerSimpleBase extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler {}
} else {
    // Shopware 6.5 and older: Use a basic class
    abstract class PaymentHandlerSimpleBase {}
}

/**
 * Simplified Payment Handler using composition with version compatibility
 * - Shopware 6.7+: Extends AbstractPaymentHandler for unified registry
 * - Shopware 6.5: Implements AsynchronousPaymentHandlerInterface via dynamic registration
 * - Older versions: Basic composition pattern
 */
class PaymentHandlerSimple extends PaymentHandlerSimpleBase implements \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
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

    // Version-specific method implementations
    // In 6.7+: These will be overridden by AbstractPaymentHandler methods
    // In 6.5: These will be the actual interface implementations for AsynchronousPaymentHandlerInterface
    
    public function supports(
        mixed $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $this->handler->supports($type, $paymentMethodId, $context);
    }

    // This method signature works for 6.5 AsynchronousPaymentHandlerInterface
    // In 6.7+, AbstractPaymentHandler will override this with its own signature
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        if (method_exists($this->handler, 'pay')) {
            return $this->handler->pay($transaction, $dataBag, $salesChannelContext);
        }
        
        throw new \BadMethodCallException('Pay method not available');
    }

    // This method signature works for 6.5 AsynchronousPaymentHandlerInterface  
    // In 6.7+, AbstractPaymentHandler will override this with its own signature
    public function finalize(
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
}
