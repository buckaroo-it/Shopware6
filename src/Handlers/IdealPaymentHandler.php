<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\Service\CaptureService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Symfony\Component\HttpFoundation\RedirectResponse;
class IdealPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Ideal::class;

    public const IDEAL_PROCESSING = 'idealProcessing';

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $order = $transaction->getOrder();
        $orderNumber = $order->getOrderNumber();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $amount = $order->getAmountTotal();

        $redirectUrl = $this->buildGatewayRedirectUrl($orderNumber, $amount, $currency);
        return new RedirectResponse($redirectUrl);
    }

    private function buildGatewayRedirectUrl(string $orderNumber, float $amount, string $currency): string
    {
        // Construct the URL based on your payment gateway’s requirements
        $baseUrl = "https://payment-gateway.com/pay"; // Replace with actual gateway URL
        $query = http_build_query([
            'order_id' => $orderNumber,
            'amount' => $amount,
            'currency' => $currency,
            'return_url' => 'https://your-shop.com/finish', // Shopware finish page after payment
            'cancel_url' => 'https://your-shop.com/cancel'  // Shopware cancel page if payment is cancelled
        ]);

        return "$baseUrl?$query";
    }



    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($this->withoutIssuers($salesChannelContext->getSalesChannelId())) {
            return [
                'continueOnIncomplete' => true
            ];
        }
        return [];
    }

    private function withoutIssuers(string $salesChannelId): bool
    {
        return $this->getSetting("idealShowissuers", $salesChannelId) === false;
    }
}
