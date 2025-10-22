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
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;

class PaymentServiceDecorator
{
    private OrderConverter $orderConverter;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private EntityRepository $orderRepository;

    private SalesChannelContextService $salesChannelContextService;

    public function __construct(
        OrderConverter $orderConverter,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        EntityRepository $orderRepository,
        SalesChannelContextService $salesChannelContextService
    ) {
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
        $this->salesChannelContextService = $salesChannelContextService;
    }

    /**
     * Assemble sales channel context with proper context inheritance and security
     *
     * @param string $paymentToken
     * @param Context $validationContext Context with appropriate permissions for token validation
     * @return SalesChannelContext
     * @throws PaymentException
     */
    public function assembleSalesChannelContext(string $paymentToken, Context $validationContext): SalesChannelContext
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

        // Step 1: Use the provided validation context for initial lookup
        // This ensures the caller has proper permissions for this operation
        $minimalCriteria = new Criteria();
        $minimalCriteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $minimalCriteria->addAssociation('salesChannel'); // Only load sales channel
        
        /** @var OrderEntity|null $minimalOrder */
        $minimalOrder = $this->orderRepository->search($minimalCriteria, $validationContext)->first();

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
        $salesChannelAwareContext = $this->createSalesChannelAwareContext($salesChannel->getId(), $validationContext);
        
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
        // Create a proper sales channel context that enforces sales channel boundaries
        // This ensures that data access is properly scoped to the specific sales channel
        
        // Generate a unique token to prevent context data sharing between concurrent requests
        $uniqueToken = 'payment-context-' . bin2hex(random_bytes(16));
        
        $salesChannelContextParams = new SalesChannelContextServiceParameters(
            $salesChannelId,
            $uniqueToken, // Use unique token to prevent concurrent request interference
            null, // languageId - derive from context
            null, // currencyId - derive from context
            null, // domainId - derive from context
            $baseContext // Use the base context to preserve permissions
        );
        
        // Get the sales channel context and extract its underlying context
        // This context will have proper sales channel awareness and security constraints
        $salesChannelContext = $this->salesChannelContextService->get($salesChannelContextParams);
        
        return $salesChannelContext->getContext();
    }
}
