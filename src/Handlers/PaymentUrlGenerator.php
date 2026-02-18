<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class PaymentUrlGenerator
{
    public function __construct(
        private readonly AsyncPaymentService $asyncPaymentService
    ) {
    }

    public function getReturnUrl(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag
    ): string {
        if ($dataBag->has('finishUrl') && is_scalar($dataBag->get('finishUrl'))) {
            $finishUrl = (string)$dataBag->get('finishUrl');
            if (strpos($finishUrl, 'http://') === 0 || strpos($finishUrl, 'https://') === 0) {
                return $finishUrl;
            }
            return rtrim(
                $this->asyncPaymentService->urlService->forwardToRoute('frontend.home.page', []),
                "/"
            ) . (string)$finishUrl;
        }
        return $this->getDefaultReturnUrl($orderTransaction, $order);
    }

    public function getDefaultReturnUrl(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order
    ): string {
        return $this->asyncPaymentService->urlService->generateAbsoluteUrl(
            'frontend.checkout.finish.page',
            ['orderId' => $order->getId()]
        );
    }

    /**
     * Returns the cancel URL for Buckaroo redirects.
     * Uses a dedicated cancel route that redirects to cart (guest-friendly) instead of
     * the login-required edit-order page.
     */
    public function getCancelRedirectUrl(): string
    {
        return $this->asyncPaymentService->urlService->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
    }

    /**
     * @deprecated Use getCancelRedirectUrl() for guest-friendly cart redirect. Kept for backward compatibility.
     */
    public function getCancelUrl(?string $returnUrl): string
    {
        return sprintf('%s&cancel=1', $returnUrl);
    }

    public function getPushUrl(): string
    {
        return $this->asyncPaymentService->urlService->getReturnUrl('buckaroo.payment.push');
    }
}
