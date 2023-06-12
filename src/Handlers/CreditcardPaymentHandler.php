<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Creditcard;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class CreditcardPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Creditcard::class;

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
        if ($dataBag->has('creditcard')) {
            return [
                'name' => $dataBag->get('creditcard'),
            ];
        }
        return [];
    }
}
