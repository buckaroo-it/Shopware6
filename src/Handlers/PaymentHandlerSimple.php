<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Simplified Payment Handler using composition instead of class aliases
 * This approach is cleaner and more maintainable than the original class_alias approach
 */
class PaymentHandlerSimple extends AbstractPaymentHandler
{
    private object $handler;
    private string $handlerType;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        if ($this->isModernHandlerAvailable()) {
            $this->handler = new PaymentHandlerModern($asyncPaymentService);
            $this->handlerType = 'modern';
        } else {
            $this->handler = new PaymentHandlerLegacy($asyncPaymentService);
            $this->handlerType = 'legacy';
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
}
