<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\IdealProcessing;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class IdealProcessingPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = IdealProcessing::class;

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
            return [];
        }
        return [
            'issuer' => $dataBag->get('bankMethodId')
        ];
    }

    private function withoutIssuers(string $salesChannelId): bool
    {
        return $this->getSetting("idealprocessingShowissuers", $salesChannelId) === false;
    }
}
