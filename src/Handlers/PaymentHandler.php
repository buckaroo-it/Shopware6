<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Handlers\Strategy\PaymentHandlerContext;
use Buckaroo\Shopware6\Handlers\Strategy\PaymentHandlerStrategyFactory;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

// Conditional class definition for Shopware version compatibility
if (class_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\AbstractPaymentHandler')) {
    // Shopware 6.7+ - extend AbstractPaymentHandler
    class PaymentHandler extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        private PaymentHandlerContext $handlerContext;
        private PaymentHandlerStrategyFactory $strategyFactory;
        protected string $paymentClass = '';

        public function __construct(AsyncPaymentService $asyncPaymentService)
        {
            $this->strategyFactory = new PaymentHandlerStrategyFactory($asyncPaymentService);
            $strategy = $this->strategyFactory->createStrategy();
            $this->handlerContext = new PaymentHandlerContext($strategy);
            
            // If this instance has a payment class set, transfer it to the strategy
            if (!empty($this->paymentClass)) {
                $this->handlerContext->setPaymentClass($this->paymentClass);
            }
        }

        /**
         * Expose a way for specific method handlers to set their Buckaroo class.
         */
        public function setPaymentClass(string $paymentClass): void
        {
            $this->handlerContext->setPaymentClass($paymentClass);
        }

        public function supports(
            mixed $type,
            string $paymentMethodId,
            Context $context
        ): bool {
            return $this->handlerContext->supports($type, $paymentMethodId, $context);
        }

        /**
         * Modern payment method for Shopware 6.7+
         */
        public function pay(
            Request $request,
            PaymentTransactionStruct $transaction,
            Context $context,
            ?Struct $validateStruct = null
        ): ?RedirectResponse {
            dd([
                'method' => 'PaymentHandler::pay (Modern 6.7+)',
                'paymentClass' => $this->paymentClass,
                'transactionId' => $transaction->getOrderTransactionId(),
                'returnUrl' => $transaction->getReturnUrl(),
                'requestData' => $request->request->all(),
                'requestQuery' => $request->query->all(),
                'strategyName' => $this->getStrategyName(),
                'validateStruct' => $validateStruct ? get_class($validateStruct) : 'null'
            ]);
            
            return $this->handlerContext->pay($request, $transaction, $context, $validateStruct);
        }

        /**
         * Modern finalize method for Shopware 6.7+
         */
        public function finalize(
            Request $request,
            PaymentTransactionStruct $transaction,
            Context $context
        ): void {
            $this->handlerContext->finalize($request, $transaction, $context);
        }

        /**
         * Get the current strategy name being used
         */
        public function getStrategyName(): string
        {
            return $this->handlerContext->getStrategyName();
        }

        /**
         * Check if modern strategy is available
         */
        public function isModernStrategyAvailable(): bool
        {
            return $this->strategyFactory->isModernStrategyAvailable();
        }

        /**
         * Switch strategy (useful for testing or manual override)
         */
        public function switchToStrategy(string $strategyName): void
        {
            $strategy = match ($strategyName) {
                'modern' => $this->strategyFactory->isModernStrategyAvailable() 
                    ? $this->strategyFactory->createStrategy() 
                    : throw new \InvalidArgumentException('Modern strategy is not available'),
                'legacy' => $this->strategyFactory->createStrategy(), // Will create legacy if modern not available
                default => throw new \InvalidArgumentException("Unknown strategy: $strategyName")
            };
            
            $this->handlerContext->setStrategy($strategy);
        }
    }
} else {
    // Shopware 6.6 - implement AsynchronousPaymentHandlerInterface
    class PaymentHandler implements \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
    {
        private PaymentHandlerContext $handlerContext;
        private PaymentHandlerStrategyFactory $strategyFactory;
        protected string $paymentClass = '';

        public function __construct(AsyncPaymentService $asyncPaymentService)
        {
            $this->strategyFactory = new PaymentHandlerStrategyFactory($asyncPaymentService);
            $strategy = $this->strategyFactory->createStrategy();
            $this->handlerContext = new PaymentHandlerContext($strategy);
            
            // If this instance has a payment class set, transfer it to the strategy
            if (!empty($this->paymentClass)) {
                $this->handlerContext->setPaymentClass($this->paymentClass);
            }
        }

        /**
         * Expose a way for specific method handlers to set their Buckaroo class.
         */
        public function setPaymentClass(string $paymentClass): void
        {
            $this->handlerContext->setPaymentClass($paymentClass);
        }

        public function supports(
            mixed $type,
            string $paymentMethodId,
            Context $context
        ): bool {
            return $this->handlerContext->supports($type, $paymentMethodId, $context);
        }

        /**
         * Legacy payment method for Shopware 6.6
         */
        public function pay(
            AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext
        ): RedirectResponse {
            dd([
                'method' => 'PaymentHandler::pay (Legacy 6.6)',
                'paymentClass' => $this->paymentClass,
                'transactionId' => $transaction->getOrderTransactionId(),
                'returnUrl' => $transaction->getReturnUrl(),
                'dataBag' => $dataBag->all(),
                'paymentMethodId' => $salesChannelContext->getPaymentMethod()->getId(),
                'paymentMethodName' => $salesChannelContext->getPaymentMethod()->getName(),
                'paymentMethodHandler' => $salesChannelContext->getPaymentMethod()->getHandlerIdentifier(),
                'strategyName' => $this->getStrategyName(),
                'POST_data' => $_POST ?? 'no POST data'
            ]);
            
            return $this->payLegacy($transaction, $dataBag, $salesChannelContext);
        }

        /**
         * Legacy finalize method for Shopware 6.6
         */
        public function finalize(
            AsyncPaymentTransactionStruct $transaction,
            Request $request,
            SalesChannelContext $salesChannelContext
        ): void {
            $this->finalizeLegacy($transaction, $request, $salesChannelContext);
        }

        /**
         * Legacy pay method implementation
         */
        private function payLegacy(
            AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext
        ): RedirectResponse {
            dd([
                'method' => 'PaymentHandler::payLegacy (conversion)',
                'originalDataBag' => $dataBag->all(),
                'transactionId' => $transaction->getOrderTransactionId(),
                'returnUrl' => $transaction->getReturnUrl(),
                'paymentMethodId' => $salesChannelContext->getPaymentMethod()->getId(),
                'converting_to_modern' => 'yes'
            ]);
            
            // Convert legacy parameters to modern format and delegate to strategy
            $request = new Request();
            $request->request->replace($dataBag->all());
            
            // Create modern PaymentTransactionStruct from legacy AsyncPaymentTransactionStruct
            $modernTransaction = new PaymentTransactionStruct(
                $transaction->getOrderTransactionId(),
                $transaction->getReturnUrl()
            );
            
            $result = $this->handlerContext->pay($request, $modernTransaction, $salesChannelContext->getContext(), null);
            
            if ($result === null) {
                throw new \RuntimeException('Payment handler returned null redirect response');
            }
            
            return $result;
        }

        /**
         * Legacy finalize method implementation
         */
        private function finalizeLegacy(
            AsyncPaymentTransactionStruct $transaction,
            Request $request,
            SalesChannelContext $salesChannelContext
        ): void {
            // Convert legacy parameters to modern format and delegate to strategy
            $modernTransaction = new PaymentTransactionStruct(
                $transaction->getOrderTransactionId(),
                $transaction->getReturnUrl()
            );
            
            $this->handlerContext->finalize($request, $modernTransaction, $salesChannelContext->getContext());
        }

        /**
         * Get the current strategy name being used
         */
        public function getStrategyName(): string
        {
            return $this->handlerContext->getStrategyName();
        }

        /**
         * Check if modern strategy is available
         */
        public function isModernStrategyAvailable(): bool
        {
            return $this->strategyFactory->isModernStrategyAvailable();
        }

        /**
         * Switch strategy (useful for testing or manual override)
         */
        public function switchToStrategy(string $strategyName): void
        {
            $strategy = match ($strategyName) {
                'modern' => $this->strategyFactory->isModernStrategyAvailable() 
                    ? $this->strategyFactory->createStrategy() 
                    : throw new \InvalidArgumentException('Modern strategy is not available'),
                'legacy' => $this->strategyFactory->createStrategy(), // Will create legacy if modern not available
                default => throw new \InvalidArgumentException("Unknown strategy: $strategyName")
            };
            
            $this->handlerContext->setStrategy($strategy);
        }
    }
}

