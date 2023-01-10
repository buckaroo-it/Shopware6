<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
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

    public function saveTransactionData(string $orderTransactionId, Context $context, array $data): void
    {
        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $orderTransactionId
        );

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields = array_merge($customFields, $data);

        $this->updateTransactionCustomFields($orderTransactionId, $customFields);
    }


    public function updateTransactionCustomFields(string $orderTransactionId, array $customFields): void
    {
        $data = [
            'id'           => $orderTransactionId,
            'customFields' => $customFields,
        ];

        $this->transactionRepository->update([$data], Context::createDefaultContext());
    }
    public function getOrderTransactionById(Context $context, string $orderTransactionId): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $filter   = new EqualsFilter('order_transaction.id', $orderTransactionId);
        $criteria->addFilter($filter);

        return $this->transactionRepository->search($criteria, $context)->first();
    }

    public function getRefundTransactionInvoceId($invoiceIncrementId, $orderTransactionId, $customFields)
    {
        $refundIncrementInvoceId = $customFields['refundIncrementInvoceId'] ?? 0;
        $refundIncrementInvoceId++;
        $customFields['refundIncrementInvoceId'] = $refundIncrementInvoceId;
        $this->updateTransactionCustomFields($orderTransactionId, $customFields);
        return $invoiceIncrementId . '_R' . ($refundIncrementInvoceId > 1 ? $refundIncrementInvoceId : '');
    }

    /**
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrderTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        return $this->transactionRepository->search($criteria, $context)
            ->get($transactionId);
    }
    
    public function getCustomFields(OrderEntity $order, Context $context)
    {
        $transaction = $order->getTransactions()->last();

        $orderTransaction = $this->getOrderTransactionById(
            $context,
            $transaction->getId()
        );
        $customField = $orderTransaction->getCustomFields() ?? [];

        $method_path                = str_replace('Handlers', 'PaymentMethods', str_replace('PaymentHandler', '', $transaction->getPaymentMethod()->getHandlerIdentifier()));
        $paymentMethod              = new $method_path;
        $customField['canRefund']   = $paymentMethod->canRefund() ? 1 : 0;
        $customField['canCapture']   = $paymentMethod->canCapture() ? 1 : 0;
        $customField['serviceName'] = $paymentMethod->getBuckarooKey();
        $customField['version']     = $paymentMethod->getVersion();

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
        $transaction = $order->getTransactions()->last();

        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        $plugin = $transaction->getPaymentMethod()->getPlugin();

        $baseClassArr         = explode('\\', $plugin->getBaseClass());
        $buckarooPaymentClass = explode('\\', BuckarooPayments::class);

        return end($baseClassArr) === end($buckarooPaymentClass);
    }

}
