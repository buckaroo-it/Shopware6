<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;

class BuckarooTransactionService
{
    protected OrderService $orderService;

    protected FormatRequestParamService $formatRequestParamService;

    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    public function __construct(
        OrderService $orderService,
        FormatRequestParamService $formatRequestParamService,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository
    ) {
        $this->orderService = $orderService;
        $this->formatRequestParamService = $formatRequestParamService;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
    }

    /**
     *
     * @param string $orderId
     * @param Context $context
     *
     * @return array<mixed>
     */
    public function getBuckarooTransactionsByOrderId(string $orderId, Context $context): array
    {
        $transactionsToRefund = [];
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
                    'salesChannel'
                ],
                $context
            );

        if ($order === null) {
            throw new \Exception("Cannot find order with id " . $orderId, 1);
        }

        $taxRules = $order->getPrice()->getTaxRules();
        $vat = $taxRules->first();
        if ($vat !== null) {
            $vat = $vat->getTaxRate();
        }

        $vat_show  = null;
        $ii = 0;
        foreach ($taxRules->getElements() as $taxRate) {
            $vat_show .= ($ii > 0 ? "/" : "") . "plus " . $taxRate->getTaxRate() . "% VAT";
            $ii++;
        }

        $orderItems = $this->formatRequestParamService->getOrderLinesArray($order);
        $items['orderItems'] = $orderItems;
        $items['transactions'] = [];

        $shipping       = $order->getShippingCosts();
        $shipping_costs = $shipping->getTotalPrice();

        $orderRefundedItems = [];

        $orderTransactions = $order->getTransactions();
        $lastOrderTransaction = $orderTransactions !== null ? $orderTransactions->last() : null;
        $orderTransactionKey = null;
        if ($lastOrderTransaction !== null) {
            $customFields = $lastOrderTransaction->getCustomFields() ?? [];
            $orderTransactionKey = $customFields['originalTransactionKey'] ?? null;
        }

        $collection = $this->buckarooTransactionEntityRepository->findByOrderId(
            $orderId,
            $context,
            ['created_at' => 'DESC']
        );
        foreach ($collection as $buckarooTransactionEntity) {
            $refunded_items = $buckarooTransactionEntity->get("refunded_items");
            if (is_scalar($refunded_items)) {
                $orderRefundedItems[] = json_decode((string)$refunded_items, true);
            }

            if (!$buckarooTransactionEntity->get("transaction_method")) {
                continue;
            }
            $transactions = $buckarooTransactionEntity->get("transactions");
            if (empty($transactions) && !empty($orderTransactionKey)) {
                $transactions = $orderTransactionKey;
            }

            if (empty($transactions)) {
                continue;
            }

            if (in_array($transactions, $transactionsToRefund)) {
                continue;
            }
            array_push($transactionsToRefund, $transactions);

            $amount = 0;

            if (
                is_scalar($buckarooTransactionEntity->get("amount"))
            ) {
                $amount = (float)$buckarooTransactionEntity->get("amount");
            }

            if (
                is_scalar($buckarooTransactionEntity->get("amount_credit"))
            ) {
                $amount -= (float)$buckarooTransactionEntity->get("amount_credit");
            }

            $createdAt = $buckarooTransactionEntity->get("created_at");
            $formatedCreatedAt = '';
            if ($createdAt instanceof \DateTime) {
                $formatedCreatedAt = $createdAt->format('Y-m-d H:i:s');
            }

            $items['transactions'][] = (object) [
                'id'                  => $buckarooTransactionEntity->get("id"),
                'transaction'         => $transactions,
                'statuscode'          => $buckarooTransactionEntity->get("statuscode"),
                'total'               => $amount,
                'shipping_costs'      => $shipping_costs,
                'vat'                 => $vat_show,
                'total_excluding_vat' => $vat ? round(($amount - (($amount / 100) * $vat)), 2) : $amount,
                'transaction_method'  => $buckarooTransactionEntity->get("transaction_method"),
                'created_at'          => $formatedCreatedAt,
            ];

            if (
                $buckarooTransactionEntity->get("amount") &&
                $buckarooTransactionEntity->get("statuscode") == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS
            ) {
                $items['transactionsToRefund'][] = (object) [
                    'id'                 => $buckarooTransactionEntity->get("id"),
                    'transactions'       => $transactions,
                    'total'              => $amount,
                    'currency'           => $buckarooTransactionEntity->get("currency"),
                    'transaction_method' => $buckarooTransactionEntity->get("transaction_method"),
                ];
            }
        }

        if (empty($items['transactions']) && !empty($orderTransactionKey) && $lastOrderTransaction !== null) {
            $paymentMethod = $lastOrderTransaction->getPaymentMethod();
            $transactionMethod = 'payperemail';
            if ($paymentMethod !== null && $paymentMethod->getCustomFields() !== null) {
                $transactionMethod = $paymentMethod->getCustomFields()['buckaroo_key'] ?? 'payperemail';
            }

            $createdAt = $lastOrderTransaction->getCreatedAt();
            $formattedCreatedAt = '';
            if ($createdAt instanceof \DateTime) {
                $formattedCreatedAt = $createdAt->format('Y-m-d H:i:s');
            }

            $amount = $order->getAmountTotal();
            $items['transactions'][] = (object) [
                'id'                  => $lastOrderTransaction->getId(),
                'transaction'         => $orderTransactionKey,
                'statuscode'          => null,
                'total'               => $amount,
                'shipping_costs'      => $shipping_costs,
                'vat'                 => $vat_show,
                'total_excluding_vat' => $vat ? round(($amount - (($amount / 100) * $vat)), 2) : $amount,
                'transaction_method'  => $transactionMethod,
                'created_at'          => $formattedCreatedAt,
            ];
        }

        foreach ($orderRefundedItems as $value) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $key3 => $quantity) {
                if (!is_scalar($quantity)) {
                    continue;
                }
                foreach ($items['orderItems'] as $key2 => $value2) {
                    if (isset($value2['id']) && $key3 == $value2['id']) {
                        $totalAmount = (float)$value2['totalAmount']['value'] - ((float)$value2['unitPrice']['value'] * (int)$quantity); //phpcs:ignore Generic.Files.LineLength.TooLong
                        $items['orderItems'][$key2]['quantity'] = (int)$value2['quantity'] - (int)$quantity;
                        $items['orderItems'][$key2]['totalAmount']['value'] = $totalAmount;
                        if ($items['orderItems'][$key2]['quantity'] < 0) {
                            $items['orderItems'][$key2]['quantity'] = 0;
                        }
                    }
                }
            }
        }
        
        // Calculate refund totals on backend - frontend should not calculate
        $items['refundTotals'] = $this->calculateRefundTotals($items['orderItems']);
        
        return $items;
    }

    /**
     * Calculate refund totals from order items
     * This is the single source of truth for refund amounts
     *
     * @param array<mixed> $orderItems
     * @return array<string, mixed>
     */
    private function calculateRefundTotals(array $orderItems): array
    {
        $totalAmount = 0.0;
        $currency = 'EUR';

        foreach ($orderItems as $item) {
            if (
                is_array($item) &&
                isset($item['totalAmount']) &&
                is_array($item['totalAmount']) &&
                isset($item['totalAmount']['value'])
            ) {
                $totalAmount += (float)$item['totalAmount']['value'];
            }
        }

        if (
            isset($orderItems[0]) &&
            is_array($orderItems[0]) &&
            isset($orderItems[0]['totalAmount']) &&
            is_array($orderItems[0]['totalAmount']) &&
            isset($orderItems[0]['totalAmount']['currency']) &&
            is_string($orderItems[0]['totalAmount']['currency'])
        ) {
            $currency = $orderItems[0]['totalAmount']['currency'];
        }

        return [
            'totalAmount' => round($totalAmount, 2),
            'currency' => $currency
        ];
    }
}
