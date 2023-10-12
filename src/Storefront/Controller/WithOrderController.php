<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Service\Exceptions\ControllerException;

abstract class WithOrderController extends StorefrontController
{
    protected OrderService $orderService;

    public function __construct(
        OrderService $orderService,
    ) {
        $this->orderService = $orderService;
    }

    protected function getOrder(Request $request, Context $context): OrderEntity
    {
        $orderId = $request->get('transaction');

        if (empty($orderId) || !is_string($orderId)) {
            throw new ControllerException($this->trans("buckaroo-payment.missing_order_id"));
        }

        $order = $this->orderService
            ->getOrderById(
                $orderId,
                [
                    'orderCustomer.salutation',
                    'stateMachineState',
                    'lineItems',
                    'transactions',
                    'transactions.paymentMethod',
                    'transactions.paymentMethod.plugin',
                    'salesChannel',
                    'currency'
                ],
                $context
            );

        if (null === $order) {
            throw new ControllerException($this->trans("buckaroo-payment.missing_transaction"));
        }

        return $order;
    }
}
