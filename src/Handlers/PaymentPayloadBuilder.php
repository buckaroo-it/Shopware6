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
        // Append context token to return URL so it's preserved when user returns from Buckaroo (external domain).
        // Session cookie may not be sent on cross-site redirects - token in URL ensures we can restore context.
        $contextToken = $salesChannelContext->getToken();
        $separator = str_contains($finalReturnUrl, '?') ? '&' : '?';
        $finalReturnUrl .= $separator . 'sw-context-token=' . rawurlencode($contextToken);
        
        return [
            'order'         => $order->getOrderNumber(),
            'invoice'       => $order->getOrderNumber(),
            'amountDebit'   => $this->feeCalculator->getOrderTotalWithFee($order, $salesChannelId, $paymentCode),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $finalReturnUrl,
            'returnURLCancel' => $this->urlGenerator->getCancelRedirectUrl($salesChannelContext->getToken()),
            'pushURL'       => $this->urlGenerator->getPushUrl(),
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
}
