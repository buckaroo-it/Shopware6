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
            // Normalize finish URL for external PSP usage:
            // - keep absolute URLs as-is but strip transient flags like changedPayment
            // - for relative URLs, prefix with storefront base and strip changedPayment as well
            $finishUrl = $this->stripChangedPaymentFlag($finishUrl);

            if (strpos($finishUrl, 'http://') === 0 || strpos($finishUrl, 'https://') === 0) {
                return $finishUrl;
            }

            return rtrim(
                $this->asyncPaymentService->urlService->forwardToRoute('frontend.home.page', []),
                '/'
            ) . $finishUrl;
        }
        return $this->getDefaultReturnUrl($orderTransaction, $order);
    }

    public function getDefaultReturnUrl(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order
    ): string {
        return $this->asyncPaymentService->urlService->forwardToRoute(
            'frontend.checkout.finish.page',
            ['orderId' => $order->getId()]
        );
    }

    public function getCancelUrl(?string $returnUrl): string
    {
        return sprintf('%s&cancel=1', $returnUrl);
    }

    public function getPushUrl(): string
    {
        return $this->asyncPaymentService->urlService->getReturnUrl('buckaroo.payment.push');
    }

    /**
     * Strip the Shopware internal "changedPayment" flag from finish URLs before
     * sending them to Buckaroo. When this flag is present in the return URL,
     * Shopware may treat the flow as an "edit order / change payment method"
     * scenario and redirect to the protected account edit-order page, which in
     * turn can trigger an unwanted login redirect.
     */
    private function stripChangedPaymentFlag(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        if (!isset($query['changedPayment'])) {
            return $url;
        }

        unset($query['changedPayment']);
        $newQuery = http_build_query($query);

        $scheme   = $parts['scheme'] ?? null;
        $host     = $parts['host'] ?? null;
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $queryStr = $newQuery !== '' ? '?' . $newQuery : '';

        // If original URL was relative, keep it relative
        if ($scheme === null && $host === null) {
            return $path . $queryStr . $fragment;
        }

        $schemePart = $scheme !== null ? $scheme . '://' : '';
        $hostPart   = $host !== null ? $host : '';

        return $schemePart . $hostPart . $port . $path . $queryStr . $fragment;
    }
}
