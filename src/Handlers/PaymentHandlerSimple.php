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

/**
 * Simplified Payment Handler that provides version compatibility
 * Uses method overloading to handle both Shopware 6.5 and 6.7 signatures
 */
class PaymentHandlerSimple
{
    protected string $paymentClass;
    
    protected AsyncPaymentService $asyncPaymentService;
    protected ?FormatRequestParamService $formatRequestParamService = null;

    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        ?FormatRequestParamService $formatRequestParamService = null
    ) {
        $this->asyncPaymentService = $asyncPaymentService;
        $this->formatRequestParamService = $formatRequestParamService;
    }

    public function setPaymentClass(string $paymentClass): void
    {
        $this->paymentClass = $paymentClass;
    }

    protected function getPaymentClass(): string
    {
        return $this->paymentClass ?? '';
    }

    public function supports(mixed $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    // Handle method calls dynamically to support both versions
    public function __call(string $method, array $args)
    {
        if ($method === 'pay') {
            return $this->handlePay($args);
        } elseif ($method === 'finalize') {
            return $this->handleFinalize($args);
        }
        
        throw new \BadMethodCallException("Method {$method} not found");
    }

    private function handlePay(array $args)
    {
        if (count($args) === 4 && $args[0] instanceof Request) {
            // Shopware 6.7+ signature: pay(Request, PaymentTransactionStruct, Context, ?Struct)
            return $this->payModern($args[0], $args[1], $args[2], $args[3]);
        } elseif (count($args) === 3 && $args[0] instanceof AsyncPaymentTransactionStruct) {
            // Shopware 6.5 signature: pay(AsyncPaymentTransactionStruct, RequestDataBag, SalesChannelContext)
            return $this->payLegacy($args[0], $args[1], $args[2]);
        }
        
        throw new \InvalidArgumentException('Invalid pay method arguments');
    }

    private function handleFinalize(array $args): void
    {
        if (count($args) === 3 && $args[0] instanceof Request) {
            // Shopware 6.7+ signature: finalize(Request, PaymentTransactionStruct, Context)
            $this->finalizeModern($args[0], $args[1], $args[2]);
        } elseif (count($args) === 3 && $args[0] instanceof AsyncPaymentTransactionStruct) {
            // Shopware 6.5 signature: finalize(AsyncPaymentTransactionStruct, Request, SalesChannelContext)
            $this->finalizeLegacy($args[0], $args[1], $args[2]);
        } else {
            throw new \InvalidArgumentException('Invalid finalize method arguments');
        }
    }

    protected function payModern(
        Request $request, 
        PaymentTransactionStruct $transaction, 
        Context $context, 
        ?Struct $validateStruct
    ): ?RedirectResponse {
        throw new \BadMethodCallException('payModern method must be implemented by child classes');
    }

    protected function payLegacy(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        throw new \BadMethodCallException('payLegacy method must be implemented by child classes');
    }

    protected function finalizeModern(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        // No-op by default
    }

    protected function finalizeLegacy(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // No-op by default
    }
}
