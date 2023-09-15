<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\Refund\Builder;
use Shopware\Core\System\StateMachine\Transition;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\Refund\ResponseHandler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\Refund\OrderRefundData;
use Buckaroo\Shopware6\Buckaroo\Refund\RefundDataInterface;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Buckaroo\Shopware6\Buckaroo\Refund\Order\ReturnPaymentRecord;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntity;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnEntity;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnStates;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnDefinition;

class ReturnService
{
    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected Builder $refundBuilder;

    protected ResponseHandler $refundResponseHandler;

    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected StateMachineRegistry $stateMachineRegistry;
    
    public function __construct(
        TransactionService $transactionService,
        TranslatorInterface $translator,
        Builder $refundBuilder,
        ResponseHandler $refundResponseHandler,
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->transactionService = $transactionService;
        $this->translator = $translator;
        $this->refundBuilder = $refundBuilder;
        $this->refundResponseHandler = $refundResponseHandler;
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->stateMachineRegistry = $stateMachineRegistry;
    }

    public function refundAll(
        OrderReturnEntity $orderReturn,
        Context $context
    ): array {

        $order = $orderReturn->getOrder();
        $amount = $orderReturn->getAmountTotal();

        if ($amount === null) {
            return [];
        }
        $paymentRecords = $this->getPaymentRecords($order->getId());

        $response = [];
        foreach ($paymentRecords as $paymentRecord) {
            $response[] = $this->refund($order, $context, $paymentRecord, $amount);
        }

        if ($this->refundsAreSuccessful($response)) {
            $this->setOrderReturnState(
                $orderReturn->getId(),
                OrderReturnStates::STATE_DONE,
                $context
            );
        }
        return $response;
    }

    /**
     * Do a buckaroo refund request
     *
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array<mixed>|null
     */
    protected function refund(
        OrderEntity $order,
        Context $context,
        ReturnPaymentRecord $paymentRecord,
        float $amount
    ): ?array {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);
        $configCode = $this->getConfigCode($customFields);
        if ($amount <= 0) {
            return [];
        }

        return $this->handleRefund(
            new OrderRefundData(
                $order,
                $paymentRecord,
                $amount
            ),
            Request::createFromGlobals(),
            $context,
            [],
            $configCode
        );
    }

    /**
     * Handle the build and execution of the refund request
     *
     * @param RefundDataInterface $refundData
     * @param Request $request
     * @param Context $context
     * @param array $orderItems
     * @param string $configCode
     *
     * @return array
     */
    protected function handleRefund(
        RefundDataInterface $refundData,
        Request $request,
        Context $context,
        array $orderItems,
        string $configCode
    ): array {
        $client = $this->refundBuilder->build(
            $refundData,
            $request,
            $configCode
        );

        return $this->refundResponseHandler->handle(
            $client->execute(),
            $refundData,
            $context,
            $orderItems,
        );
    }

    /**
     * @param array<mixed> $customFields
     *
     * @return string
     */
    public function getConfigCode(
        array $customFields
    ): string {
        if (!is_string($customFields['serviceName'])) {
            throw new \InvalidArgumentException('Service name is not a string');
        }
        return $customFields['serviceName'];
    }

    /**
     * Get payment records from the database
     *
     * @param string $orderId
     *
     * @return array
     */
    private function getPaymentRecords(string $orderId): array
    {
        $transactions = $this->buckarooTransactionEntityRepository->findByOrderId($orderId);

        $paymentRecords = [];

        
        foreach ($transactions as $transaction) {
            $transactionKey = $transaction->get("transactions");

            if (!in_array($transactionKey, array_keys($paymentRecords))) {
                $paymentRecords[$transactionKey] = new ReturnPaymentRecord(
                    $transaction,
                    $this->getTransactionAmount($transaction)
                );
            } else {
                $paymentRecords[$transactionKey]->addAmount(
                    $this->getTransactionAmount($transaction)
                );
            }
        }
        return array_values($paymentRecords);
    }

    /**
     * Get transaction amount
     *
     * @param BuckarooTransactionEntity $transaction
     *
     * @return float
     */
    private function getTransactionAmount(BuckarooTransactionEntity $transaction): float
    {
        $amount = 0;

        if (
            is_scalar($transaction->get("amount"))
        ) {
            $amount = (float)$transaction->get("amount");
        }

        if (
            is_scalar($transaction->get("amount_credit"))
        ) {
            $amount -= (float)$transaction->get("amount_credit");
        }
        return $amount;
    }

    private function setOrderReturnState(string $orderReturnId, string $state, Context $context)
    {
        try {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderReturnDefinition::ENTITY_NAME,
                    $orderReturnId,
                    $state,
                    'stateId'
                ),
                $context
            );
        } catch (\Throwable $th) {
            throw new \Exception("Invalid order return status transition", 0, $th);
        }
        
    }

    private function refundsAreSuccessful(array $responses): bool
    {
        foreach ($responses as $response) {
            if (!$response['status']) {
                return false;
            }
        }
        return true;
    }
}
