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

// Determine base class/interface based on Shopware version
if (interface_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface') && 
    !class_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')) {
    // Shopware 6.5 and older: Implement AsynchronousPaymentHandlerInterface
    class PaymentHandlerSimple implements \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
    {
        protected AsyncPaymentService $asyncPaymentService;
        protected ?FormatRequestParamService $formatRequestParamService;
        public string $paymentClass = '';

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
            return $this->paymentClass;
        }

        public function supports(mixed $type, string $paymentMethodId, Context $context): bool
        {
            return true;
        }

        // Template methods for child classes to override
        public function getMethodPayload(
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): array {
            return [];
        }

        public function getMethodAction(
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): string {
            return 'pay';
        }

        // Shopware 6.5 AsynchronousPaymentHandlerInterface methods
        public function pay(
            AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext
        ): \Symfony\Component\HttpFoundation\RedirectResponse {
            return $this->payLegacy($transaction, $dataBag, $salesChannelContext);
        }

        public function finalize(
            AsyncPaymentTransactionStruct $transaction,
            \Symfony\Component\HttpFoundation\Request $request,
            SalesChannelContext $salesChannelContext
        ): void {
            $this->finalizeLegacy($transaction, $request, $salesChannelContext);
        }

        protected function payLegacy(
            AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext
        ): \Symfony\Component\HttpFoundation\RedirectResponse {
            // Create a custom legacy handler that delegates template methods to this instance
            $legacyHandler = new class($this->asyncPaymentService, $this) extends PaymentHandlerLegacy {
                private PaymentHandlerSimple $parent;
                
                public function __construct(AsyncPaymentService $asyncPaymentService, PaymentHandlerSimple $parent) {
                    parent::__construct($asyncPaymentService);
                    $this->parent = $parent;
                    if (!empty($parent->paymentClass)) {
                        $this->setPaymentClass($parent->paymentClass);
                    }
                }
                
                // Delegate template methods to the parent
                protected function getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode): array {
                    return $this->parent->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                }
                
                protected function getMethodAction($dataBag, $salesChannelContext = null, $paymentCode = null): string {
                    return $this->parent->getMethodAction($dataBag, $salesChannelContext, $paymentCode);
                }
            };
            
            return $legacyHandler->pay($transaction, $dataBag, $salesChannelContext);
        }

        protected function finalizeLegacy(
            AsyncPaymentTransactionStruct $transaction,
            \Symfony\Component\HttpFoundation\Request $request,
            SalesChannelContext $salesChannelContext
        ): void {
            // Finalization logic for Shopware 6.5
            // For most payment methods, no additional action is needed
        }
    }

} else {
    // Shopware 6.7+: Extend AbstractPaymentHandler
    class PaymentHandlerSimple extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        protected AsyncPaymentService $asyncPaymentService;
        protected ?FormatRequestParamService $formatRequestParamService;
        public string $paymentClass = '';

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
            return $this->paymentClass;
        }

        public function supports(mixed $type, string $paymentMethodId, Context $context): bool
        {
            return true;
        }

        // Template methods for child classes to override
        public function getMethodPayload(
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): array {
            return [];
        }

        public function getMethodAction(
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): string {
            return 'pay';
        }

        // Shopware 6.7 AbstractPaymentHandler methods
        public function pay(
            \Symfony\Component\HttpFoundation\Request $request,
            PaymentTransactionStruct $transaction,
            Context $context,
            ?Struct $validateStruct = null
        ): ?\Symfony\Component\HttpFoundation\RedirectResponse {
            return $this->payModern($request, $transaction, $context, $validateStruct);
        }

        public function finalize(
            \Symfony\Component\HttpFoundation\Request $request,
            PaymentTransactionStruct $transaction,
            Context $context
        ): void {
            $this->finalizeModern($request, $transaction, $context);
        }

        protected function payModern(
            \Symfony\Component\HttpFoundation\Request $request,
            PaymentTransactionStruct $transaction,
            Context $context,
            ?Struct $validateStruct = null
        ): ?\Symfony\Component\HttpFoundation\RedirectResponse {
            // For Shopware 6.7, we need to implement a simpler approach
            // since we don't have the complex dependencies of PaymentHandlerModern
            
            // Extract basic data from the request
            $dataBag = new RequestDataBag($request->request->all());
            
            // For now, return a simple redirect since we don't have proper sales channel context
            // In a real implementation, you would need to inject the proper dependencies
            // to construct a valid SalesChannelContext and order repository
            
            // Get payment configuration with minimal data
            $paymentCode = 'pay'; // Default action without calling child methods
            
            // For PaymentTransactionStruct in 6.7, we need to get order through a different approach
            // The transaction only has getOrderTransactionId(), so we need to construct order data differently
            // For now, pass null as order since we don't have access to repository here
            $order = null; // In real implementation, you would need to inject order repository
            $salesChannelContext = null; // This would need proper construction
            
            // Since we can't call template methods without proper context, return a basic redirect
            // Child classes should override this method to provide proper implementation
            
            // For now, return a simple redirect (implement according to your needs)
            return new \Symfony\Component\HttpFoundation\RedirectResponse('/buckaroo/payment/process');
        }

        protected function finalizeModern(
            \Symfony\Component\HttpFoundation\Request $request,
            PaymentTransactionStruct $transaction,
            Context $context,
            ?Struct $validateStruct = null
        ): void {
            // Finalization logic for Shopware 6.7
            // For most payment methods, no additional action is needed
        }
    }
}
