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
            try {
                // Get the transaction ID and fetch the order transaction entity
                $transactionId = $transaction->getOrderTransactionId();
                $orderTransaction = $this->asyncPaymentService->getTransaction($transactionId, $context);
                
                if ($orderTransaction === null) {
                    return null; // Payment failed - transaction not found
                }
                
                $order = $orderTransaction->getOrder();
                if ($order === null) {
                    return null; // Payment failed - order not found
                }
                
                // Get sales channel context - use a default token if not provided
                $contextToken = $request->get('sw-context-token', '');
                $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
                    $context,
                    $order->getSalesChannelId(),
                    is_string($contextToken) ? $contextToken : ''
                );
                
                // Extract request data
                $dataBag = new RequestDataBag($request->request->all());
                
                // For Shopware 6.7, we can't use AsyncPaymentTransactionStruct anymore
                // Instead, we'll implement the payment logic directly without legacy delegation
                
                // Get payment class instance
                if (empty($this->paymentClass)) {
                    throw new \Exception('Payment class not set. Call setPaymentClass() before using the handler.');
                }
                
                $paymentClass = null;
                if (class_exists($this->paymentClass)) {
                    $paymentClass = new $this->paymentClass();
                }
                
                if ($paymentClass === null || !$paymentClass instanceof \Buckaroo\Shopware6\PaymentMethods\AbstractPayment) {
                    throw new \Exception('Invalid buckaroo payment class');
                }
                
                $salesChannelId = $salesChannelContext->getSalesChannelId();
                $paymentCode = $paymentClass->getBuckarooKey();
                
                // Validate order - simplified validation for 6.7
                if ($order->getAmountTotal() <= 0) {
                    // For zero amount, we should complete the payment immediately
                    // This is a simplified version - in production you might want more logic
                    return new \Symfony\Component\HttpFoundation\RedirectResponse($transaction->getReturnUrl() ?? '/checkout/finish');
                }
                
                // Get client from AsyncPaymentService
                $client = $this->asyncPaymentService->clientService->get($paymentCode, $salesChannelId);
                
                // Build payload using template methods
                $methodPayload = $this->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                $methodAction = $this->getMethodAction($dataBag, $salesChannelContext, $paymentCode);
                
                // Get common payload (simplified - you might need to add more fields based on your needs)
                $commonPayload = [
                    'invoice' => $order->getOrderNumber(),
                    'amountDebit' => $order->getAmountTotal(),
                    'currency' => $order->getCurrency()->getIsoCode(),
                    'returnURL' => $transaction->getReturnUrl(),
                    'returnURLCancel' => $transaction->getReturnUrl(),
                    'returnURLError' => $transaction->getReturnUrl(),
                    'returnURLReject' => $transaction->getReturnUrl(),
                ];

                // Set client payload and action
                $client->setPayload(array_merge_recursive($commonPayload, $methodPayload))
                       ->setAction($methodAction);
                
                // Execute payment request
                $response = $client->execute();

                // Handle the response
                if ($response && $response->hasRedirect()) {
                    return new \Symfony\Component\HttpFoundation\RedirectResponse($response->getRedirectUrl());
                }
                
                // If no redirect URL, return to finish page
                return new \Symfony\Component\HttpFoundation\RedirectResponse($transaction->getReturnUrl() ?? '/checkout/finish');
                
            } catch (\Exception $e) {
                // Log the error and return null to indicate payment failure
                $this->asyncPaymentService->logger->error('Payment processing failed in modern handler', [
                    'error' => $e->getMessage(),
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentClass' => $this->paymentClass
                ]);
                return null;
            }
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
