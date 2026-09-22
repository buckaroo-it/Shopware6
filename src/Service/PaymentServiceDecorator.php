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
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Symfony\Component\HttpFoundation\RequestStack;

class PaymentServiceDecorator
{
    private OrderConverter $orderConverter;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private EntityRepository $orderRepository;

    private SalesChannelContextServiceInterface $salesChannelContextService;

    private RequestStack $requestStack;

    public function __construct(
        OrderConverter $orderConverter,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        EntityRepository $orderRepository,
        SalesChannelContextServiceInterface $salesChannelContextService,
        RequestStack $requestStack
    ) {
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
        $this->salesChannelContextService = $salesChannelContextService;
        $this->requestStack = $requestStack;
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

        // Step 2: Create context with sales channel constraints.
        // Use existing context token from request when available to preserve session state (sw-states)
        // for checkout/finish page - avoids redirect to register/cart when returning from external payment.
        $contextToken = $this->getContextTokenFromRequest();
        $salesChannelAwareContext = $this->createSalesChannelAwareContext(
            $salesChannel->getId(),
            $validationContext,
            $contextToken
        );
        
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
     * Get context token from current request (cookie or Buckaroo add_ params).
     * Preserves customer session when returning from external payment gateway.
     */
    private function getContextTokenFromRequest(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        $token = $request->attributes->get('sw-context-token')
            ?? $request->query->get('add_sw-context-token')
            ?? $request->request->get('add_sw-context-token')
            ?? $request->query->get('sw-context-token')
            ?? $request->request->get('sw-context-token')
            ?? $request->cookies->get('sw-context-token');
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Create a context that's aware of the sales channel for security constraints
     *
     * @param string $salesChannelId
     * @param Context $baseContext
     * @param string|null $preferredToken Use existing token to preserve session (sw-states) for checkout finish
     * @return Context
     */
    private function createSalesChannelAwareContext(
        string $salesChannelId,
        Context $baseContext,
        ?string $preferredToken = null
    ): Context {
        // Use existing context token when available to preserve checkout state (sw-states).
        // Otherwise generate unique token to prevent context data sharing between concurrent requests.
        $token = $preferredToken ?? ('payment-context-' . bin2hex(random_bytes(16)));
        
        $salesChannelContextParams = new SalesChannelContextServiceParameters(
            $salesChannelId,
            $token,
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
