<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class IdealPaymentHandler extends PaymentHandlerSimple
{
    public const IDEAL_PROCESSING = 'idealProcessing';

    public string $paymentClass = Ideal::class;

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
        if ($dataBag->get('idealFastCheckoutInfo')) {
            $shippingCost = 0;
            $firstDelivery = $order->getDeliveries()?->first();

            if ($firstDelivery && $firstDelivery->getShippingCosts()) {
                $shippingCost = $firstDelivery->getShippingCosts()->getTotalPrice();
            }

            return [
                'orderId' => $dataBag->get('orderId'),
                'shippingCost' => $shippingCost,
                'customer' => [
                    'email' => $order->getOrderCustomer()?->getEmail(),
                    'firstName' => $order->getOrderCustomer()?->getFirstName(),
                    'lastName' => $order->getOrderCustomer()?->getLastName(),
                ],
            ];
        }

        return [
            'customer' => [
                'email' => $order->getOrderCustomer()?->getEmail(),
                'firstName' => $order->getOrderCustomer()?->getFirstName(),
                'lastName' => $order->getOrderCustomer()?->getLastName(),
            ],
        ];
    }

    /**
     * Get method action for specific payment method
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return $dataBag->get('idealFastCheckoutInfo') ? 'payFastCheckout' : 'pay';
    }
}
