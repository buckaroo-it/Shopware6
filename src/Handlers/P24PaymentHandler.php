<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\P24;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class P24PaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = P24::class;

    /**
     * Get parameters for specific payment method
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $address = $this->asyncPaymentService->getBillingAddress($order);

        return [
            'email' => $this->asyncPaymentService->getCustomer($order)->getEmail(),
            'customer' => [
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName(),
            ],
        ];
    }
}
