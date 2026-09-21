<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PaymentResponseHandler
{
    public function __construct(
        private readonly AsyncPaymentService $asyncPaymentService
    ) {
    }

    public function handleResponse(
        ClientResponseInterface $response,
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        string $paymentCode,
        string $returnUrl
    ): RedirectResponse {
        $this->storeTransactionInfo($orderTransaction, $order, $response, $salesChannelContext, $paymentCode);

        if ($response->isRejected()) {
            throw new \Buckaroo\Shopware6\Service\Exceptions\BuckarooPaymentRejectException(
                $response->getSubCodeMessage()
            );
        }

        if ($response->hasRedirect()) {
            $this->handleRedirectResponse($orderTransaction);
            return new RedirectResponse($response->getRedirectUrl());
        }

        return $this->handlePaymentStatus($response, $orderTransaction, $salesChannelContext, $returnUrl, $paymentCode);
    }

    public function handleZeroAmountPayment(
        OrderTransactionEntity $orderTransaction,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $this->asyncPaymentService->stateTransitionService->transitionPaymentState(
            'paid',
            $orderTransaction->getId(),
            $salesChannelContext->getContext()
        );
        return $this->redirectToFinishPage($orderTransaction);
    }

    private function storeTransactionInfo(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        ClientResponseInterface $response,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $this->asyncPaymentService->checkoutHelper->appendCustomFields(
            $order->getId(),
            [
                'buckaroo_payment_in_test_mode' => $response->isTestMode(),
            ],
            $salesChannelContext->getContext()
        );
        
        $this->asyncPaymentService->transactionService->updateTransactionCustomFields($orderTransaction->getId(), [
            'originalTransactionKey' => $response->getTransactionKey()
        ], $salesChannelContext->getContext());
        
        // Fee is now applied before payment request, not after response
        // This ensures the order total in Shopware matches the amount sent to Buckaroo
    }

    private function handlePaymentStatus(
        ClientResponseInterface $response,
        OrderTransactionEntity $orderTransaction,
        SalesChannelContext $salesChannelContext,
        string $returnUrl,
        string $paymentCode
    ): RedirectResponse {
        if (
            $response->isSuccess() ||
            $response->isAwaitingConsumer() ||
            $response->isPendingProcessing() ||
            $response->isWaitingOnUserInput()
        ) {
            if (!$response->isSuccess()) {
                $this->asyncPaymentService->stateTransitionService->transitionPaymentState(
                    'pending',
                    $orderTransaction->getId(),
                    $salesChannelContext->getContext()
                );
            }
            return $this->redirectToFinishPage($orderTransaction);
        }

        if ($response->isCanceled()) {
            throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                $orderTransaction->getId(),
                'Payment was canceled',
                new \Exception('Payment was canceled')
            );
        }

        if ($response->isFailed() || $response->isValidationFailure()) {
            throw \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                $orderTransaction->getId(),
                'Payment failed: ' . $response->getSomeError(),
                new \Exception('Payment failed with status code: ' . $response->getStatusCode())
            );
        }

        return new RedirectResponse(
            sprintf('%s&brq_payment_method=%s&brq_statuscode=%s', $returnUrl, $paymentCode, $response->getStatusCode())
        );
    }

    /**
     * Remembers the order the customer was last redirected to Buckaroo for, so the
     * storefront back button and the cancel route can offer "payment canceled" instead
     * of a bare cart page.
     *
     * This is a storefront only convenience and must stay optional: the payment handler
     * that reaches this code also runs without a session (store-api / headless
     * checkouts), and the cancel route recovers the same order id from the
     * additionalParameters Buckaroo posts back.
     */
    private function handleRedirectResponse(OrderTransactionEntity $orderTransaction): void
    {
        $order = $orderTransaction->getOrder();
        if ($order === null) {
            return;
        }

        $session = $this->asyncPaymentService->checkoutHelper->getSessionIfAvailable();
        if ($session === null) {
            return;
        }

        $session->set('buckaroo_latest_order', $order->getId());
    }

    private function redirectToFinishPage(OrderTransactionEntity $orderTransaction): RedirectResponse
    {
        $order = $orderTransaction->getOrder();
        if ($order === null) {
            throw new \RuntimeException('Order not found for transaction');
        }
        return new RedirectResponse(
            $this->asyncPaymentService->urlService->generateAbsoluteUrl(
                'frontend.checkout.finish.page',
                ['orderId' => $order->getId()]
            )
        );
    }
}
