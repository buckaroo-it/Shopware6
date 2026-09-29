<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Subscribers\PaymentContextCookieSubscriber;
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

        // The browser may drop the session cookie when Buckaroo redirects back cross-site. The context
        // token travels in a dedicated cookie instead of the return URL, so it never leaves the shop.
        PaymentContextCookieSubscriber::rememberForReturn(
            $this->asyncPaymentService->checkoutHelper->getCurrentRequest(),
            $salesChannelContext
        );

        return [
            'order'         => $order->getOrderNumber(),
            'invoice'       => $order->getOrderNumber(),
            'amountDebit'   => $this->feeCalculator->getOrderTotalWithFee($order, $salesChannelId, $paymentCode),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $finalReturnUrl,
            'returnURLCancel' => $this->urlGenerator->getCancelRedirectUrlForOrder(
                $order,
                $salesChannelContext->getContext(),
                null,
                $finalReturnUrl
            ),
            'pushURL'       => $this->urlGenerator->getPushUrl(
                $order,
                $salesChannelContext->getContext(),
                $finalReturnUrl
            ),
            'additionalParameters' => [
                'orderTransactionId' => $orderTransaction->getId(),
                'orderId' => $order->getId(),
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
