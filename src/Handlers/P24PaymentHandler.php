<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\P24;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class P24PaymentHandler extends PaymentHandler
{
    protected string $paymentClass = P24::class;


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
        $address  = $this->asyncPaymentService->getBillingAddress($order);

        return [
            'email'    => $this->asyncPaymentService->getCustomer($order)->getEmail(),
            'customer' => [
                'firstName' => $address->getFirstName(),
                'lastName'  => $address->getLastName(),
            ]
        ];
    }
}
