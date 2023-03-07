<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Payment\Exception\InvalidTokenException;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class PaymentServiceDecorator
{
    private OrderConverter $orderConverter;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private EntityRepository $orderRepository;

    public function __construct(
        OrderConverter $orderConverter,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        EntityRepository $orderRepository
    ) {
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
    }

    public function assembleSalesChannelContext(string $paymentToken): SalesChannelContext
    {
        $context = Context::createDefaultContext();

        $parsedToken = $this->tokenFactoryInterfaceV2->parseToken($paymentToken);
        $transactionId = $parsedToken->getTransactionId();

        if ($transactionId === null) {
            throw new InvalidTokenException($paymentToken);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('orderCustomer');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new InvalidTokenException($paymentToken);
        }

        return $this->orderConverter->assembleSalesChannelContext($order, $context);
    }
}
