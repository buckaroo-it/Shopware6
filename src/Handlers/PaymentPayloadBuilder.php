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

        if (!str_contains($finalReturnUrl, '_sw_payment_token')) {
            $contextToken = $salesChannelContext->getToken();
            if (!empty($contextToken)) {
                $separator = str_contains($finalReturnUrl, '?') ? '&' : '?';
                $finalReturnUrl .= $separator . 'sw-context-token=' . rawurlencode($contextToken);
            }
        }
        
        return [
            'order'         => $order->getOrderNumber(),
            'invoice'       => $order->getOrderNumber(),
            'amountDebit'   => $this->feeCalculator->getOrderTotalWithFee($order, $salesChannelId, $paymentCode),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $finalReturnUrl,
            'returnURLCancel' => $this->urlGenerator->getCancelRedirectUrlForOrder($order, $salesChannelContext->getToken()),
            'pushURL'       => $this->urlGenerator->getPushUrl($order),
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
