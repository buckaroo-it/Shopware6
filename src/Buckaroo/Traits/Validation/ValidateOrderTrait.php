<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Traits\Validation;

use Shopware\Core\Checkout\Order\OrderEntity;

trait ValidateOrderTrait
{
    private function validateOrder(OrderEntity $order): void
    {
        $deliveries = $order->getDeliveries();

        if ($deliveries === null) {
            throw new \InvalidArgumentException('Deliveries cannot be null');
        }

        $shippingAddress = null;
        $firstDelivery = $deliveries->first();
        if ($firstDelivery !== null) {
            $shippingAddress = $firstDelivery->getShippingOrderAddress();
        }
        
        if ($shippingAddress === null) {
            $shippingAddress = $order->getBillingAddress();
        }

        if ($shippingAddress === null) {
            throw new \InvalidArgumentException('Shipping Address cannot be null');
        }

        if ($shippingAddress->getCountry() === null) {
            throw new \InvalidArgumentException('Shipping Address country cannot be null');
        }

        $billingAddress = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();

        if ($billingAddress === null || $customer === null) {
            throw new \InvalidArgumentException('Billing Address and customer cannot be null');
        }

        if ($billingAddress->getCountry() === null) {
            throw new \InvalidArgumentException('Billing Address country cannot be null');
        }
    }
}
