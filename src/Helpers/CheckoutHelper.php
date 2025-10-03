<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CheckoutHelper
{

    private SettingsService $settingsService;

    private EntityRepository $orderRepository;

    private BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected RequestStack $requestStack;

    public function __construct(
        SettingsService $settingsService,
        EntityRepository $orderRepository,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        RequestStack $requestStack
    ) {
        $this->settingsService                     = $settingsService;
        $this->orderRepository                     = $orderRepository;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->requestStack = $requestStack;
    }

    /**
     * Get the current session with proper null safety
     *
     * @return SessionInterface
     * @throws \RuntimeException When no session is active
     */
    public function getSession(): SessionInterface
    {
        $session = $this->requestStack->getSession();
        
        if ($session === null) {
            throw new \RuntimeException(
                'No active session found. Session is required for payment processing.'
            );
        }
        
        return $session;
    }

    /**
     *
     * @param string $orderId
     * @param float $fee
     * @param Context $context
     *
     * @return void
     * @throws \Exception When order is not found or fee calculation fails
     */
    public function applyFeeToOrder(string $orderId, float $fee, Context $context): void
    {
        if (empty($orderId)) {
            throw new \InvalidArgumentException('Order ID cannot be empty');
        }

        $order = $this->getOrderById($orderId, $context);
        if ($order === null) {
            throw new \Exception("Order with ID {$orderId} not found", 1);
        }

        $price = $order->getPrice();
        if ($price === null) {
            throw new \Exception("Order price information is missing", 1);
        }

        $savedCustomFields = $order->getCustomFields() ?? [];
        $buckarooFee = $fee;

        // Adjust fee if there's already a buckaroo fee applied
        if (isset($savedCustomFields['buckarooFee']) && is_numeric($savedCustomFields['buckarooFee'])) {
            $buckarooFee = $buckarooFee - (float)$savedCustomFields['buckarooFee'];
        }
        
        $savedCustomFields['buckarooFee'] = $fee;

        // Only update if there's an actual fee change
        if ($buckarooFee !== 0.0) {
            $data = [
                'id'           => $orderId,
                'customFields' => $savedCustomFields,
                'price' => new CartPrice(
                    $price->getNetPrice() + $buckarooFee,
                    $price->getTotalPrice() + $buckarooFee,
                    $price->getPositionPrice(),
                    $price->getCalculatedTaxes(),
                    $price->getTaxRules(),
                    $price->getTaxStatus()
                )
            ];

            $this->orderRepository->update([$data], $context);
        }
    }

    /**
     * Append additional data to order custom fields
     *
     * @param string $orderId
     * @param array<mixed> $customFields
     * @param Context $context
     *
     * @return void
     * @throws \Exception When order is not found
     * @throws \InvalidArgumentException When parameters are invalid
     */
    public function appendCustomFields(string $orderId, array $customFields, Context $context): void
    {
        if (empty($orderId)) {
            throw new \InvalidArgumentException('Order ID cannot be empty');
        }

        if (empty($customFields)) {
            return; // Nothing to update
        }

        $order = $this->getOrderById($orderId, $context);
        if ($order === null) {
            throw new \Exception("Order with ID {$orderId} not found", 1);
        }

        $savedCustomFields = $order->getCustomFields() ?? [];
        
        $this->orderRepository->update(
            [['id' => $orderId, 'customFields' => array_merge($savedCustomFields, $customFields)]],
            $context
        );
    }




    /**
     * Get order by ID with proper context handling
     *
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity|null
     */
    public function getOrderById(string $orderId, Context $context): ?OrderEntity
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.stateMachineState');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');
        $orderCriteria->addAssociation('deliveries');

        /** @var \Shopware\Core\Checkout\Order\OrderEntity|null */
        return $this->orderRepository->search($orderCriteria, $context)->first();
    }

    public function saveBuckarooTransaction(Request $request, Context $context): ?string
    {
        return $this->buckarooTransactionEntityRepository->save(null, $this->pusToArray($request), [], $context);
    }

    /**
     * Convert push request to array format with robust type handling
     *
     * @param Request $request
     *
     * @return array<mixed>
     */
    public function pusToArray(Request $request): array
    {
        $now = new \DateTime();
        $type = 'push';
        
        // Use strict comparison to prevent type coercion issues
        $transactionType = $request->request->get('brq_transaction_type');
        if (is_string($transactionType) && $transactionType === 'I150') {
            $type = 'info';
        }
        
        return [
            'order_id'             => $this->sanitizeRequestValue($request->request->get('ADD_orderId')),
            'order_transaction_id' => $this->sanitizeRequestValue($request->request->get('ADD_orderTransactionId')),
            'amount'               => $this->sanitizeRequestValue($request->request->get('brq_amount')),
            'amount_credit'        => $this->sanitizeRequestValue($request->request->get('brq_amount_credit')),
            'currency'             => $this->sanitizeRequestValue($request->request->get('brq_currency')),
            'ordernumber'          => $this->sanitizeRequestValue($request->request->get('brq_invoicenumber')),
            'statuscode'           => $this->sanitizeRequestValue($request->request->get('brq_statuscode')),
            'transaction_method'   => $this->sanitizeRequestValue($request->request->get('brq_transaction_method')),
            'transaction_type'     => $this->sanitizeRequestValue($transactionType),
            'transactions'         => $this->sanitizeRequestValue($request->request->get('brq_transactions')),
            'relatedtransaction'   => $this->sanitizeRequestValue(
                $request->request->get('brq_relatedtransaction_partialpayment')
            ),
            'type'                 => $type,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    /**
     * Sanitize request values with proper type safety and validation
     *
     * @param mixed $value
     * @return string|null
     */
    private function sanitizeRequestValue($value): ?string
    {
        // Handle null values
        if ($value === null) {
            return null;
        }
        
        // Handle empty strings
        if ($value === '') {
            return null;
        }
        
        // Reject complex data types that cannot be safely converted to strings
        if (is_array($value)) {
            // Log warning for debugging - arrays should not be in request parameters
            error_log('Warning: Array value found in request parameter, rejecting: ' . json_encode($value));
            return null;
        }
        
        if (is_object($value)) {
            // Log warning for debugging - objects should not be in request parameters
            error_log('Warning: Object value found in request parameter, rejecting: ' . get_class($value));
            return null;
        }
        
        if (is_resource($value)) {
            // Resources cannot be safely converted to strings
            error_log('Warning: Resource value found in request parameter, rejecting');
            return null;
        }
        
        // Handle scalar values (string, int, float, bool)
        if (is_scalar($value)) {
            // Convert to string with explicit type safety
            return (string)$value;
        }
        
        // Fallback for unexpected types
        error_log('Warning: Unexpected value type in request parameter: ' . gettype($value));
        return null;
    }

    /**
     * @param string $value
     * @param string|null $salesChannelId
     *
     * @return mixed
     */
    public function getSettingsValue(string $value, ?string $salesChannelId = null)
    {
        return $this->settingsService->getSetting($value, $salesChannelId);
    }

    /**
     * Compare two amounts for equality with proper type safety and precision handling
     *
     * @param mixed $amount1
     * @param mixed $amount2
     *
     * @return boolean
     */
    public function areEqualAmounts($amount1, $amount2): bool
    {
        if (!is_scalar($amount1) || !is_scalar($amount2)) {
            return false;
        }

        // Convert to float with explicit type conversion to avoid type juggling
        $float1 = (float)$amount1;
        $float2 = (float)$amount2;

        // Use strict equality for zero comparison to avoid type juggling
        if ($float2 === 0.0) {
            return $float1 === 0.0;
        }
        
        // For non-zero amounts, use relative precision comparison
        return abs(($float1 - $float2) / $float2) < 0.00001;
    }
}
