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
     * Appends sw-context-token when provided so session is preserved on return (cross-site redirect).
     *
     * @param string|null $contextToken Sales channel context token to preserve session
     */
    public function getCancelRedirectUrl(?string $contextToken = null): string
    {
        $url = $this->asyncPaymentService->urlService->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
        if ($contextToken !== null && $contextToken !== '') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'sw-context-token=' . rawurlencode($contextToken);
        }
        return $url;
    }

    /**
     * @deprecated Use getCancelRedirectUrl() for guest-friendly cart redirect. Kept for backward compatibility.
     */
    public function getCancelUrl(?string $returnUrl): string
    {
        return sprintf('%s&cancel=1', $returnUrl);
    }

    /**
     * Returns the push URL for Buckaroo callbacks.
     * Uses the order's sales channel domain so the URL includes the correct language path (e.g. /en).
     */
    public function getPushUrl(OrderEntity $order): string
    {
        return $this->asyncPaymentService->urlService->getPushUrlForOrder($order);
    }
}
