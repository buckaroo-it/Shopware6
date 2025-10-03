<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

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
 * Enhanced PaymentHandlerSimple using Adapter pattern to bridge Strategy pattern with required interfaces
 *
 * This class uses conditional compilation but delegates to a clean strategy pattern internally.
 * This gives us the best of both worlds:
 * - Clean architecture with Strategy pattern
 * - Proper interface compliance for Shopware's payment system
 */

// Determine base class/interface based on Shopware version
if (interface_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface') &&
    !class_exists('\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler')) {
    // Shopware 6.5: Implement AsynchronousPaymentHandlerInterface + use Strategy pattern internally
    class PaymentHandlerSimple implements
        \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
    {
        use PaymentHandlerTemplateMethods;
        
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

        // Shopware 6.5 interface implementation - delegates to legacy strategy
        public function pay(
            AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext
        ): RedirectResponse {
            // Use delegation to PaymentHandlerLegacy
            $legacyHandler = new class($this->asyncPaymentService, $this) extends PaymentHandlerLegacy {
                private PaymentHandlerSimple $parent;
                
                public function __construct(AsyncPaymentService $asyncPaymentService, PaymentHandlerSimple $parent)
                {
                    parent::__construct($asyncPaymentService);
                    $this->parent = $parent;
                    if (!empty($parent->paymentClass)) {
                        $this->setPaymentClass($parent->paymentClass);
                    }
                }
                
                protected function getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode): array
                {
                    return $this->parent->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                }
                
                protected function getMethodAction($dataBag, $salesChannelContext = null, $paymentCode = null): string
                {
                    return $this->parent->getMethodAction($dataBag, $salesChannelContext, $paymentCode);
                }
            };
            
            return $legacyHandler->pay($transaction, $dataBag, $salesChannelContext);
        }

        public function finalize(
            AsyncPaymentTransactionStruct $transaction,
            Request $request,
            SalesChannelContext $salesChannelContext
        ): void {
            // For most payment methods, no additional action is needed
        }
    }

} else {
    // Shopware 6.7+: Extend AbstractPaymentHandler + use Strategy pattern internally
    class PaymentHandlerSimple extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        use PaymentHandlerTemplateMethods;
        
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

        // Shopware 6.7 interface implementation - uses modern strategy
        public function pay(
            Request $request,
            PaymentTransactionStruct $transaction,
            Context $context,
            ?Struct $validateStruct = null
        ): ?RedirectResponse {
            try {
                // Get transaction and order
                $transactionId = $transaction->getOrderTransactionId();
                $orderTransaction = $this->asyncPaymentService->getTransaction($transactionId, $context);
                
                if ($orderTransaction === null) {
                    return null;
                }
                
                $order = $orderTransaction->getOrder();
                if ($order === null) {
                    return null;
                }
                
                // Get sales channel context
                $contextToken = $request->get('sw-context-token', '');
                $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
                    $context,
                    $order->getSalesChannelId(),
                    is_string($contextToken) ? $contextToken : ''
                );
                
                // Extract request data
                $dataBag = new RequestDataBag($request->request->all());
                
                // Get payment configuration
                if (empty($this->paymentClass)) {
                    throw new \Exception('Payment class not set.');
                }
                
                $paymentClass = new $this->paymentClass();
                if (!$paymentClass instanceof \Buckaroo\Shopware6\PaymentMethods\AbstractPayment) {
                    throw new \Exception('Invalid payment class.');
                }
                
                $paymentCode = $paymentClass->getBuckarooKey();
                
                // Handle zero amount payments
                if ($order->getAmountTotal() <= 0) {
                    return new RedirectResponse($transaction->getReturnUrl() ?? '/checkout/finish');
                }
                
                // Process payment using existing services
                $client = $this->asyncPaymentService->clientService->get(
                    $paymentCode,
                    $salesChannelContext->getSalesChannelId()
                );
                
                $methodPayload = $this->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                $methodAction = $this->getMethodAction($dataBag, $salesChannelContext, $paymentCode);
                
                $commonPayload = [
                    'invoice' => $order->getOrderNumber(),
                    'amountDebit' => $order->getAmountTotal(),
                    'currency' => $order->getCurrency()->getIsoCode(),
                    'returnURL' => $transaction->getReturnUrl(),
                ];
                
                $client->setPayload(array_merge_recursive($commonPayload, $methodPayload))
                       ->setAction($methodAction);
                
                $response = $client->execute();
                
                if ($response && $response->hasRedirect()) {
                    return new RedirectResponse($response->getRedirectUrl());
                }
                
                return new RedirectResponse($transaction->getReturnUrl() ?? '/checkout/finish');
            } catch (\Exception $e) {
                $this->asyncPaymentService->logger->error('Payment processing failed', [
                    'error' => $e->getMessage(),
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentClass' => $this->paymentClass
                ]);
                return null;
            }
        }

        public function finalize(
            Request $request,
            PaymentTransactionStruct $transaction,
            Context $context
        ): void {
            // For most payment methods, no additional action is needed
        }
    }
}
