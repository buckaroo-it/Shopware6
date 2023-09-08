<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Refund;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Buckaroo\Refund\RefundDataInterface;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;

class ResponseHandler
{

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected StateTransitionService $stateTransitionService;


    public function __construct(
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        TransactionService $transactionService,
        StateTransitionService $stateTransitionService,
        TranslatorInterface $translator,
    ) {
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->transactionService = $transactionService;
        $this->stateTransitionService = $stateTransitionService;
        $this->translator = $translator;
    }


    /**
     * Handle response from payment engine
     *
     * @param ClientResponseInterface $response
     * @param RefundDataInterface $refundData
     * @param Context $context
     * @param array $orderItems
     *
     * @return array
     */
    public function handle(
        ClientResponseInterface $response,
        RefundDataInterface $refundData,
        Context $context,
        array $orderItems,
    ): array {

        $transactionId = $refundData->getPaymentRecord()->getId();
        $transaction = $refundData->getLastTransaction();
        $amount = $refundData->getAmount();

        if ($response->isSuccess()) {
            $status      = ($amount < $refundData->getOrder()->getAmountTotal()) ? 'partial_refunded' : 'refunded';
            $this->stateTransitionService->transitionPaymentState($status, $transaction->getId(), $context);
            $this->transactionService->saveTransactionData($transaction->getId(), $context, [$status => 1]);

            // updating refunded items in transaction
            if (count($orderItems)) {
                $orderItemsRefunded = [];
                foreach ($orderItems as $value) {
                    if (
                        is_array($value) &&
                        isset($value['id']) &&
                        isset($value['quantity']) &&
                        is_string($value['id']) &&
                        is_scalar($value['quantity'])
                    ) {
                        $orderItemsRefunded[$value['id']] = $value['quantity'];
                    }
                }
                $orderItems = '';

                $refunded_items = '';

                $bkTransaction = $this->buckarooTransactionEntityRepository
                    ->getById($transactionId);
                if ($bkTransaction !== null) {
                    $refunded_items = $bkTransaction->get("refunded_items");
                }

                if (!is_string($refunded_items)) {
                    $refunded_items = '';
                }

                if (!empty($refunded_items)) {
                    $refunded_items = json_decode($refunded_items, true);
                    if (is_array($refunded_items)) {
                        foreach ($refunded_items as $k => $qnt) {
                            if (!is_scalar($qnt)) {
                                $qnt = 0;
                            }
                            $qnt = (int)$qnt;
                            if (!isset($orderItemsRefunded[$k])) {
                                $orderItemsRefunded[$k] = 0;
                            }
                            $orderItemsRefunded[$k] = $orderItemsRefunded[$k] + $qnt;
                        }
                    }
                }

                $amountCredit = 0;
                $transaction = $this->buckarooTransactionEntityRepository->getById($transactionId);
                if ($transaction !== null && is_scalar($transaction->get('amount_credit'))) {
                    $amountCredit = (float)$transaction->get('amount_credit');
                }


                $this->buckarooTransactionEntityRepository
                    ->save(
                        $transactionId,
                        [
                            'refunded_items' => json_encode($orderItemsRefunded),
                            'amount_credit' => (string)($amountCredit + $amount)
                        ],
                    );
            }

            return [
                'status' => true,
                'message' => $this->translator->trans(
                    "buckaroo.refund.refunded_amount",
                    [
                        '%amount%' => $amount,
                        '%currency%' => $refundData->getCurrency()
                    ]
                )
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }
}
