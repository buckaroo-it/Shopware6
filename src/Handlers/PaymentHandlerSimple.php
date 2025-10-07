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
        protected FormatRequestParamService $formatRequestParamService;
        public string $paymentClass = '';

        public function __construct(
            AsyncPaymentService $asyncPaymentService,
            ?FormatRequestParamService $formatRequestParamService = null
        ) {
            $this->asyncPaymentService = $asyncPaymentService;
            $this->formatRequestParamService =
                $formatRequestParamService ?? $asyncPaymentService->formatRequestParamService;
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
                
                protected function getMethodPayload(
                    \Shopware\Core\Checkout\Order\OrderEntity $order,
                    \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
                    \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
                    string $paymentCode
                ): array {
                    return $this->parent->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                }
                
                protected function getMethodAction(
                    \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
                    ?\Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext = null,
                    ?string $paymentCode = null
                ): string {
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
            $this->asyncPaymentService->paymentStateService->finalizePayment(
                $transaction,
                $request,
                $salesChannelContext
            );
        }
        
        /**
         * Helper accessors used by child handlers
         */
        protected function getSetting(string $key, ?string $salesChannelId = null): mixed
        {
            return $this->asyncPaymentService->settingsService->getSetting($key, $salesChannelId);
        }

        /** @return array<mixed> */
        protected function getOrderLinesArray(
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            string $paymentCode,
            ?\Shopware\Core\Framework\Context $context = null
        ): array {
            return $this->asyncPaymentService->formatRequestParamService->getOrderLinesArray(
                $order,
                $paymentCode,
                $context
            );
        }

        protected function getFee(string $paymentCode, string $salesChannelId): float
        {
            return $this->asyncPaymentService->settingsService->getBuckarooFee($paymentCode, $salesChannelId);
        }

        /**
         * Legacy-compatible hook stub used by children like IdealQrPaymentHandler.
         * @return array<mixed>
         */
        /**
         * @param mixed $orderTransaction
         * @return array<mixed>
         */
        protected function getCommonRequestPayload(
            $orderTransaction,
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode,
            ?string $returnUrl
        ): array {
            return [
                'order' => $order->getOrderNumber(),
                'invoice' => $order->getOrderNumber(),
                'amountDebit' => $order->getAmountTotal(),
                'currency' => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
                'returnURL' => $returnUrl ?? ''
            ];
        }

        /**
         * @param mixed $orderTransaction
         */
        protected function handleResponse(
            \Buckaroo\Shopware6\Buckaroo\ClientResponseInterface $response,
            $orderTransaction,
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): RedirectResponse {
            if ($response->hasRedirect()) {
                return new RedirectResponse($response->getRedirectUrl());
            }
            return new RedirectResponse('/checkout/finish');
        }

        protected function isAfterpayOld(string $salesChannelContextId): bool
        {
            return $this->getSetting('afterpayEnabledold', $salesChannelContextId) === true;
        }
    }

} else {
    // Shopware 6.7+: Extend AbstractPaymentHandler + use Strategy pattern internally
    class PaymentHandlerSimple extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        use PaymentHandlerTemplateMethods;
        
        protected AsyncPaymentService $asyncPaymentService;
        protected FormatRequestParamService $formatRequestParamService;
        public string $paymentClass = '';

        public function __construct(
            AsyncPaymentService $asyncPaymentService,
            ?FormatRequestParamService $formatRequestParamService = null
        ) {
            $this->asyncPaymentService = $asyncPaymentService;
            $this->formatRequestParamService =
                $formatRequestParamService ?? $asyncPaymentService->formatRequestParamService;
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

                $contextToken = $this->getContextTokenFromRequest($request);
                $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
                    $context,
                    $order->getSalesChannelId(),
                    $contextToken
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
                    return new RedirectResponse($transaction->getReturnUrl());
                }

                $urlGenerator = new PaymentUrlGenerator($this->asyncPaymentService);
                $feeCalculator = new PaymentFeeCalculator($this->asyncPaymentService);
                $payloadBuilder = new PaymentPayloadBuilder(
                    $this->asyncPaymentService,
                    $urlGenerator,
                    $feeCalculator
                );
                
                // Build common payload with all required fields
                $commonPayload = $payloadBuilder->buildCommonPayload(
                    $orderTransaction,
                    $order,
                    $dataBag,
                    $salesChannelContext,
                    $paymentCode,
                    $transaction->getReturnUrl()
                );
                
                $this->asyncPaymentService->logger->info('Shopware 6.7 - Payload built', [
                    'orderId' => $order->getId(),
                    'pushURL' => $commonPayload['pushURL'] ?? '(missing)',
                    'additionalParameters' => !empty($commonPayload['additionalParameters']) ? 'present' : 'missing'
                ]);
                
                $methodPayload = $this->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
                $methodAction = $this->getMethodAction($dataBag, $salesChannelContext, $paymentCode);
                
                // Process payment using existing services
                $client = $this->asyncPaymentService->clientService->get(
                    $paymentCode,
                    $salesChannelContext->getSalesChannelId()
                );
                
                $client->setPayload(array_merge_recursive($commonPayload, $methodPayload))
                       ->setAction($methodAction);
                
                $response = $client->execute();
                
                // Check for rejected payments
                if ($response->isRejected()) {
                    throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                        $transactionId,
                        'Payment was rejected: ' . $response->getSubCodeMessage()
                    );
                }
                
                // Check for failed payments
                if ($response->isFailed() || $response->isValidationFailure()) {
                    throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                        $transactionId,
                        'Payment failed: ' . $response->getSomeError()
                    );
                }
                
                // Check for canceled payments
                if ($response->isCanceled()) {
                    throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                        $transactionId,
                        'Payment was canceled'
                    );
                }
                
                if ($response->hasRedirect()) {
                    return new RedirectResponse($response->getRedirectUrl());
                }
                
                return new RedirectResponse($transaction->getReturnUrl());
            } catch (\Shopware\Core\Checkout\Payment\PaymentException $e) {
                // Re-throw payment exceptions so they trigger error redirect
                throw $e;
            } catch (\Exception $e) {
                $this->asyncPaymentService->logger->error('Payment processing failed', [
                    'error' => $e->getMessage(),
                    'transactionId' => $transaction->getOrderTransactionId(),
                    'paymentClass' => $this->paymentClass
                ]);
                throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransactionId(),
                    'Payment processing failed: ' . $e->getMessage()
                );
            }
        }

        /**
         * Helper accessors used by child handlers
         */
        protected function getSetting(string $key, ?string $salesChannelId = null): mixed
        {
            return $this->asyncPaymentService->settingsService->getSetting($key, $salesChannelId);
        }

        /** @return array<mixed> */
        protected function getOrderLinesArray(
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            string $paymentCode,
            ?\Shopware\Core\Framework\Context $context = null
        ): array {
            return $this->asyncPaymentService->formatRequestParamService->getOrderLinesArray(
                $order,
                $paymentCode,
                $context
            );
        }

        protected function getFee(string $paymentCode, string $salesChannelId): float
        {
            return $this->asyncPaymentService->settingsService->getBuckarooFee($paymentCode, $salesChannelId);
        }

        /**
         * @param mixed $orderTransaction
         */
        /**
         * @param mixed $orderTransaction
         */
        protected function handleResponse(
            \Buckaroo\Shopware6\Buckaroo\ClientResponseInterface $response,
            $orderTransaction,
            \Shopware\Core\Checkout\Order\OrderEntity $order,
            \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
            \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): RedirectResponse {
            // Minimal default: redirect if response has redirect, otherwise finish
            if ($response->hasRedirect()) {
                return new RedirectResponse($response->getRedirectUrl());
            }
            return new RedirectResponse('/checkout/finish');
        }

        public function finalize(
            Request $request,
            PaymentTransactionStruct $transaction,
            Context $context
        ): void {
            $this->asyncPaymentService->paymentStateService->finalizePayment(
                $transaction,
                $request,
                $context
            );
        }

        /**
         * Extract context token from request (headers first, then parameters)
         */
        private function getContextTokenFromRequest(Request $request): string
        {
            $contextToken = $request->headers->get('sw-context-token', '');
            if (empty($contextToken)) {
                $contextToken = $request->get('sw-context-token', '');
            }
            if (empty($contextToken) || !is_string($contextToken)) {
                return '';
            }
            return $contextToken;
        }
    }
}
