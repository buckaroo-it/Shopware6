<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Trustly;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class TrustlyPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Trustly::class;

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
        $address = $this->asyncPaymentService->getBillingAddress($order);
        return [
            'country' => $this->asyncPaymentService->getCountry($address)->getIso(),
            'customer'      => [
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName()
            ]
        ];
    }
}
