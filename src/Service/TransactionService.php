<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\BuckarooPayments;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class TransactionService
{
    /** @var EntityRepository $transactionRepository */
    private $transactionRepository;

    public function __construct(
        EntityRepository $transactionRepository
    ) {
        $this->transactionRepository = $transactionRepository;
    }
    /**
     * Save transaction data to custom fields
     *
     * @param string $orderTransactionId
     * @param Context $context
     * @param array<mixed> $data
     *
     * @return void
     */
    public function saveTransactionData(string $orderTransactionId, Context $context, array $data): void
    {
        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $orderTransactionId
        );

        if ($orderTransaction === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields = array_merge($customFields, $data);

        $this->updateTransactionCustomFields($orderTransactionId, $customFields, $context);
    }
    /**
     * Update transaction custom fields using the provided context
     *
     * @param string $orderTransactionId
     * @param array<mixed> $customFields
     * @param Context $context The context to use for the update operation
     *
     * @return void
     */
    public function updateTransactionCustomFields(string $orderTransactionId, array $customFields, Context $context): void
    {
        $data = [
            'id'           => $orderTransactionId,
            'customFields' => $customFields,
        ];
        $this->transactionRepository->update([$data], $context);
    }

    /**
     * @param Context $context
     * @param string $orderTransactionId
     *
     * @return OrderTransactionEntity|null
     */
    public function getOrderTransactionById(Context $context, string $orderTransactionId): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $filter   = new EqualsFilter('order_transaction.id', $orderTransactionId);
        $criteria->addFilter($filter);

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        return $this->transactionRepository->search($criteria, $context)->first();
    }

    /**
     * @param string $transactionId
     * @param Context $context
     */
    public function getOrderTransaction(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        return $this->transactionRepository->search($criteria, $context)->first();
    }

    /**
     *
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array<mixed>
     */
    public function getCustomFields(OrderEntity $order, Context $context): array
    {
        $transactions = $order->getTransactions();

        if ($transactions === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        $transaction = $transactions->last();
        if ($transaction === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $transaction->getId()
        );

        if ($orderTransaction === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        $customField = $orderTransaction->getCustomFields() ?? [];

        $paymentHandler ='';

        if ($transaction->getPaymentMethod() !== null) {
            $paymentHandler = $transaction->getPaymentMethod()->getHandlerIdentifier();
        }

        $method_path = str_replace(
            'Handlers',
            'PaymentMethods',
            str_replace('PaymentHandler', '', $paymentHandler)
        );

        // Validate class existence and instantiate safely
        $customField['canRefund'] = 0;
        $customField['canCapture'] = 0;
        $customField['serviceName'] = '';
        $customField['version'] = '';

        if (!empty($method_path) && class_exists($method_path)) {
            try {
                // Verify it's a valid payment method class before instantiation
                $reflection = new \ReflectionClass($method_path);
                
                // Check if class is instantiable (not abstract/interface)
                if ($reflection->isInstantiable()) {
                    /** @var \Buckaroo\Shopware6\PaymentMethods\AbstractPayment */
                    $paymentMethod = new $method_path();
                    
                    // Verify it has the expected methods before calling them
                    if (method_exists($paymentMethod, 'canRefund')) {
                        $customField['canRefund'] = $paymentMethod->canRefund() ? 1 : 0;
                    }
                    
                    if (method_exists($paymentMethod, 'canCapture')) {
                        $customField['canCapture'] = $paymentMethod->canCapture() ? 1 : 0;
                    }
                    
                    if (method_exists($paymentMethod, 'getBuckarooKey')) {
                        $customField['serviceName'] = $paymentMethod->getBuckarooKey();
                    }
                    
                    if (method_exists($paymentMethod, 'getVersion')) {
                        $customField['version'] = $paymentMethod->getVersion();
                    }
                } else {
                    error_log("Warning: Payment method class {$method_path} is not instantiable (abstract/interface)");
                }
            } catch (\Throwable $e) {
                // Log the error but don't crash the application
                error_log("Error instantiating payment method class {$method_path}: " . $e->getMessage());
            }
        } else {
            // Log missing class for debugging
            if (!empty($method_path)) {
                error_log("Warning: Payment method class {$method_path} does not exist");
            }
        }

        return $customField;
    }

    /**
     * Check if this event is triggered using a Buckaroo Payment Method
     *
     * @param OrderEntity $order
     * @return bool
     */
    public function isBuckarooPaymentMethod(OrderEntity $order): bool
    {
        $transactions = $order->getTransactions();

        if ($transactions === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        /** @var OrderTransactionEntity|null */
        $transaction = $transactions->last();
        if ($transaction === null) {
            return false;
        }

        /** @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity|null */
        $paymentMethod = $transaction->getPaymentMethod();
        if ($paymentMethod === null) {
            return false;
        }

        /** @var \Shopware\Core\Framework\Plugin\PluginEntity|null */
        $plugin = $paymentMethod->getPlugin();
        if ($plugin === null) {
            return false;
        }

        $baseClassArr         = explode('\\', $plugin->getBaseClass());
        $buckarooPaymentClass = explode('\\', BuckarooPayments::class);

        return end($baseClassArr) === end($buckarooPaymentClass);
    }

    public function getLastTransactionId(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();

        if ($transactions === null) {
            throw new \InvalidArgumentException('Order transaction not found.');
        }

        /** @var OrderTransactionEntity|null $lastTransaction */
        $lastTransaction = $transactions->last();

        return $lastTransaction?->getId();
    }
}
