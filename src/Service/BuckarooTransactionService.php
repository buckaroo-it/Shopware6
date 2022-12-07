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
    public function getBuckarooTransactionsByOrderId($orderId, Context $context)
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

        $vat   = $order->get("price")->getTaxRules()->first()->getTaxRate();

        $vat_show  = null;
        $ii = 0;
        foreach ($order->get("price")->getTaxRules()->getElements() as $taxRate) {
            $vat_show .= ($ii > 0 ? "/" : "") . "plus " . $taxRate->getTaxRate() . "% VAT";
            $ii++;
        }

        $items['orderItems'] = $this->formatRequestParamService->getOrderLinesArray($order);

        $shipping       = $order->getShippingCosts();
        $shipping_costs = $shipping->getTotalPrice();

        $orderRefundedItems = [];

        $collection = $this->buckarooTransactionEntityRepository->findByOrderId($orderId, ['created_at' => 'DESC']);
        foreach ($collection as $buckarooTransactionEntity) {
            if ($refunded_items = $buckarooTransactionEntity->get("refunded_items")) {
                $orderRefundedItems[] = json_decode($refunded_items);
            }
            if (!$buckarooTransactionEntity->get("transaction_method")) {
                continue;
            }
            $transactions = $buckarooTransactionEntity->get("transactions");
            if (in_array($transactions, $transactionsToRefund)) {
                continue;
            }
            array_push($transactionsToRefund, $transactions);
            $amount                  = $buckarooTransactionEntity->get("amount_credit") ? '-' . $buckarooTransactionEntity->get("amount_credit") : $buckarooTransactionEntity->get("amount");
            $items['transactions'][] = (object) [
                'id'                  => $buckarooTransactionEntity->get("id"),
                'transaction'         => $buckarooTransactionEntity->get("transactions"),
                'statuscode'          => $buckarooTransactionEntity->get("statuscode"),
                'total'               => $amount,
                'shipping_costs'      => $shipping_costs,
                'vat'                 => $vat_show,
                'total_excluding_vat' => $vat ? round(($amount - (($amount / 100) * $vat)), 2) : $amount,
                'transaction_method'  => $buckarooTransactionEntity->get("transaction_method"),
                'created_at'          => $buckarooTransactionEntity->get("created_at")->format('Y-m-d H:i:s'),
            ];

            if ($buckarooTransactionEntity->get("amount") && $buckarooTransactionEntity->get("statuscode") == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
                $items['transactionsToRefund'][] = (object) [
                    'id'                 => $buckarooTransactionEntity->get("id"),
                    'transactions'       => $transactions,
                    'total'              => $amount,
                    'currency'           => $buckarooTransactionEntity->get("currency"),
                    'transaction_method' => $buckarooTransactionEntity->get("transaction_method"),
                ];
            }
        }

        foreach ($orderRefundedItems as $key => $value) {
            foreach ($value as $key3 => $quantity) {
                foreach ($items['orderItems'] as $key2 => $value2) {
                    if (isset($value2['id']) && $key3 == $value2['id']) {
                        $items['orderItems'][$key2]['quantity'] = (int)$value2['quantity'] - (int)$quantity;
                        $items['orderItems'][$key2]['totalAmount']['value'] = ((float)$value2['totalAmount']['value'] - ((float)$value2['unitPrice']['value'] * (int)$quantity));
                        if ($items['orderItems'][$key2]['quantity'] < 0) {
                            $items['orderItems'][$key2]['quantity'] = 0;
                        }
                    }
                }
            }
        }
        return $items;
    }
}
