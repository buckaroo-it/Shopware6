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

    public function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
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

    public function saveBuckarooTransaction(Request $request): ?string
    {
        return $this->buckarooTransactionEntityRepository->save(null, $this->pusToArray($request), []);
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
            'relatedtransaction'   => $this->sanitizeRequestValue($request->request->get('brq_relatedtransaction_partialpayment')),
            'type'                 => $type,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    /**
     * Sanitize request values to ensure consistent data types
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeRequestValue($value)
    {
        // Convert empty strings to null for consistency
        if ($value === '') {
            return null;
        }
        
        // Keep null values as null
        if ($value === null) {
            return null;
        }
        
        // For non-string values, convert to string for consistency with request data
        if (!is_string($value)) {
            return (string)$value;
        }
        
        return $value;
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

        if ($amount2 == 0) {
            return $amount1 == $amount2;
        } else {
            return abs((floatval($amount1) - floatval($amount2)) / floatval($amount2)) < 0.00001;
        }
    }
}
