<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

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


        if ($this->withoutIssuers($salesChannelContext->getSalesChannelId())) {
            return [
                'continueOnIncomplete' => true
            ];
        }
        return [
            'issuer' => $dataBag->get('bankMethodId')
        ];
    }

    private function withoutIssuers(string $salesChannelId): bool
    {
        return $this->getSetting("idealShowissuers", $salesChannelId) === false;
    }
}
