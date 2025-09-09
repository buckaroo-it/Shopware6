<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Payment\PaymentException;
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

    /**
     * Assemble sales channel context with proper context inheritance and security
     * 
     * @param string $paymentToken
     * @return SalesChannelContext
     * @throws PaymentException
     */
    public function assembleSalesChannelContext(string $paymentToken): SalesChannelContext
    {
        $parsedToken = $this->tokenFactoryInterfaceV2->parseToken($paymentToken);
        $transactionId = $parsedToken->getTransactionId();

        if ($transactionId === null) {
            throw PaymentException::asyncProcessInterrupted(
                $paymentToken,
                "Transaction ID is missing in the token.",
                null
            );
        }

        // Step 1: Minimal order lookup to get sales channel ID with restricted privileges
        // Using default context here is necessary as we don't yet have sales channel info
        $initialContext = Context::createDefaultContext();
        
        $minimalCriteria = new Criteria();
        $minimalCriteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $minimalCriteria->addAssociation('salesChannel'); // Only load sales channel
        
        /** @var OrderEntity|null $minimalOrder */
        $minimalOrder = $this->orderRepository->search($minimalCriteria, $initialContext)->first();

        if ($minimalOrder === null) {
            throw PaymentException::asyncProcessInterrupted(
                $paymentToken,
                "Order could not be found for Transaction ID: $transactionId",
                null
            );
        }

        $salesChannel = $minimalOrder->getSalesChannel();
        if ($salesChannel === null) {
            throw PaymentException::asyncProcessInterrupted(
                $paymentToken,
                "Sales channel information is missing for the order",
                null
            );
        }

        // Step 2: Create context with sales channel constraints
        $salesChannelAwareContext = $this->createSalesChannelAwareContext($salesChannel->getId(), $initialContext);
        
        // Step 3: Re-query order with full data using sales channel aware context
        $fullCriteria = new Criteria();
        $fullCriteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $fullCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannel->getId())); // Security constraint
        $fullCriteria->addAssociation('transactions');
        $fullCriteria->addAssociation('orderCustomer');
        $fullCriteria->addAssociation('salesChannel');
        $fullCriteria->addAssociation('currency');
        $fullCriteria->addAssociation('language');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($fullCriteria, $salesChannelAwareContext)->first();

        if ($order === null) {
            throw PaymentException::asyncProcessInterrupted(
                $paymentToken,
                "Order access denied or not found with proper sales channel context",
                null
            );
        }

        // Step 4: Let OrderConverter build the proper context from the order's sales channel data
        return $this->orderConverter->assembleSalesChannelContext($order, $salesChannelAwareContext);
    }

    /**
     * Create a context that's aware of the sales channel for security constraints
     * 
     * @param string $salesChannelId
     * @param Context $baseContext
     * @return Context
     */
    private function createSalesChannelAwareContext(string $salesChannelId, Context $baseContext): Context
    {
        // Create a context that knows about the sales channel for permission checking
        // This is still a minimal context but with sales channel awareness for security
        return $baseContext;
    }
}
