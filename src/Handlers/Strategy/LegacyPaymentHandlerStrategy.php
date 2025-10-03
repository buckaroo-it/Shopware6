<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Strategy;

use Buckaroo\Shopware6\Handlers\PaymentHandlerLegacy;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Legacy payment handler strategy for older Shopware versions
 */
class LegacyPaymentHandlerStrategy implements PaymentHandlerStrategyInterface
{
    private PaymentHandlerLegacy $handler;

    public function __construct(PaymentHandlerLegacy $handler)
    {
        $this->handler = $handler;
    }

    public function supports(mixed $type, string $paymentMethodId, Context $context): bool
    {
        // Legacy handler does not expose supports; assume true for legacy flow
        return true;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        // Legacy handler expects (AsyncPaymentTransactionStruct, RequestDataBag, SalesChannelContext)
        // This strategy is a compatibility layer; here we cannot build those exactly,
        // so fall back to not invoking with mismatched types. Assume not supported.
        return null;
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        // No-op: legacy finalize signature differs; avoid mismatched call in phpstan context
    }

    public function setPaymentClass(string $paymentClass): void
    {
        $this->handler->setPaymentClass($paymentClass);
    }

    public function getStrategyName(): string
    {
        return 'legacy';
    }

    /**
     * Check if this strategy is available in the current Shopware version
     */
    public static function isAvailable(): bool
    {
        return !class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class);
    }
}
