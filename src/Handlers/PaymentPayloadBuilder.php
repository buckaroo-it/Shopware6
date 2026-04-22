<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class PaymentPayloadBuilder
{
    public function __construct(
        private readonly AsyncPaymentService $asyncPaymentService,
        private readonly PaymentUrlGenerator $urlGenerator,
        private readonly PaymentFeeCalculator $feeCalculator
    ) {
    }

    public function buildCommonPayload(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode,
        ?string $returnUrl
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $defaultReturnUrl = $this->urlGenerator->getDefaultReturnUrl($orderTransaction, $order);
        $finalReturnUrl = $returnUrl ?: $defaultReturnUrl;

        // After Buckaroo, session cookies may be missing; sw-context-token on the return URL restores context.
        // Shopware 6.7+: do not append when the URL is payment finalize (_sw_payment_token) — breaks some Buckaroo flows.
        // Shopware 6.5–6.6: still append for finalize URLs; otherwise the customer loses context and lands on home/empty cart.
        if (!str_contains($finalReturnUrl, 'sw-context-token=')) {
            $omitContextForFinalizeUrl = str_contains($finalReturnUrl, '_sw_payment_token')
                && self::isShopwareVersionAtLeast('6.7.0');
            if (!$omitContextForFinalizeUrl) {
                $contextToken = $salesChannelContext->getToken();
                if ($contextToken !== '') {
                    $separator = str_contains($finalReturnUrl, '?') ? '&' : '?';
                    $finalReturnUrl .= $separator . 'sw-context-token=' . rawurlencode($contextToken);
                }
            }
        }

        return [
            'order'         => $order->getOrderNumber(),
            'invoice'       => $order->getOrderNumber(),
            'amountDebit'   => $this->feeCalculator->getOrderTotalWithFee($order, $salesChannelId, $paymentCode),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $finalReturnUrl,
            'returnURLCancel' => $this->urlGenerator->getCancelRedirectUrlForOrder($order, $salesChannelContext->getToken(), $finalReturnUrl),
            'pushURL'       => $this->urlGenerator->getPushUrl($order, $finalReturnUrl),
            'additionalParameters' => [
                'orderTransactionId' => $orderTransaction->getId(),
                'orderId' => $order->getId(),
                'sw-context-token' => $salesChannelContext->getToken()
            ],
            'description' => $this->asyncPaymentService->settingsService->getParsedLabel(
                $order,
                $salesChannelId,
                'transactionLabel'
            ),
            'clientIP' => $this->getClientIp(),
        ];
    }

    private function getClientIp(): array
    {
        $request = Request::createFromGlobals();
        $remoteIp = $request->getClientIp();
        return [
            'address'       => $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }

    private static function isShopwareVersionAtLeast(string $minVersion): bool
    {
        if (class_exists(\Shopware\Core\Kernel::class)) {
            try {
                $reflection = new \ReflectionClass(\Shopware\Core\Kernel::class);
                if ($reflection->hasConstant('SHOPWARE_FALLBACK_VERSION')) {
                    $shopwareVersion = $reflection->getConstant('SHOPWARE_FALLBACK_VERSION');
                    if (is_string($shopwareVersion)) {
                        return version_compare($shopwareVersion, $minVersion, '>=');
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getVersion('shopware/core');
                if ($version !== null && is_string($version)) {
                    $version = ltrim($version, 'v');
                    if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
                        $version = $matches[1];
                    }

                    return version_compare($version, $minVersion, '>=');
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }
}
