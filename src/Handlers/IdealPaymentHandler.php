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
        if ($this->withoutIssuers($salesChannelContext->getSalesChannelId()) || $dataBag->get('idealFastCheckoutInfo')) {
            return [
                'continueOnIncomplete' => true
            ];
        }
        return [
            'issuer' => $dataBag->get('bankMethodId')
        ];
    }
    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        if ($dataBag->get('idealFastCheckoutInfo')) {
            return 'PayFastCheckout';
        }
        return 'pay';
    }
    private function withoutIssuers(string $salesChannelId): bool
    {
        return $this->getSetting("idealShowissuers", $salesChannelId) === false;
    }
}
