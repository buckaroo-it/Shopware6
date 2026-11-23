<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Refuses to change the payment method of an existing order to a Buckaroo payment
 * method whose minimum / maximum order amount excludes the order total, the same
 * way Shopware refuses a payment method that is not available.
 */
class SetPaymentOrderRouteDecorator extends AbstractSetPaymentOrderRoute
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param EntityRepository<PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private readonly AbstractSetPaymentOrderRoute $decorated,
        private readonly PaymentAmountLimitService $limitService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $paymentMethodRepository
    ) {
    }

    public function getDecorated(): AbstractSetPaymentOrderRoute
    {
        return $this->decorated;
    }

    public function setPayment(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        $paymentMethodId = $request->request->getAlnum('paymentMethodId');
        $orderId = $request->request->getAlnum('orderId');

        $paymentMethod = $this->loadPaymentMethod($paymentMethodId, $context);
        $order = $paymentMethod !== null ? $this->loadOrder($orderId, $context) : null;

        if (
            $paymentMethod !== null
            && $order !== null
            && !$this->limitService->isAllowedForOrder($paymentMethod, $order)
        ) {
            throw OrderException::paymentMethodNotAvailable($paymentMethodId);
        }

        return $this->decorated->setPayment($request, $context);
    }

    /**
     * Only Buckaroo payment methods have limits; anything else is left to Shopware.
     */
    private function loadPaymentMethod(string $paymentMethodId, SalesChannelContext $context): ?PaymentMethodEntity
    {
        if (!Uuid::isValid($paymentMethodId)) {
            return null;
        }

        $paymentMethod = $this->paymentMethodRepository
            ->search(new Criteria([$paymentMethodId]), $context->getContext())
            ->first();

        if (
            !$paymentMethod instanceof PaymentMethodEntity
            || $this->limitService->getBuckarooKey($paymentMethod) === null
        ) {
            return null;
        }

        return $paymentMethod;
    }

    /**
     * The order of the logged in customer, as Shopware's route loads it. Anything
     * else is left to Shopware, which refuses it.
     */
    private function loadOrder(string $orderId, SalesChannelContext $context): ?OrderEntity
    {
        $customer = $context->getCustomer();
        if ($customer === null || !Uuid::isValid($orderId)) {
            return null;
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customer->getId()));

        $order = $this->orderRepository->search($criteria, $context->getContext())->first();

        return $order instanceof OrderEntity ? $order : null;
    }
}
