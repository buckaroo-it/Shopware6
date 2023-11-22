<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseEntity;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;

class SavedTransactionState
{

    protected EngineResponseCollection $engineResponses;

    /**
     * @var \Buckaroo\Shopware6\Buckaroo\TransactionState[]
     */
    private array $transactions;

    public function __construct(EngineResponseCollection $engineResponses)
    {
        $this->engineResponses = $engineResponses;
        $this->transactions = $this->getTransactionsWithLatestState();
    }

    private function getTransactionsWithLatestState(): array
    {
        $transactionKeys = [];

        $transactions = [];
        foreach ($this->engineResponses as $engineResponse) {
            $transactionKeys[] = $engineResponse->getTransactionKey();
        }

        foreach ($transactionKeys as $transactionKey) {
            $responses = $this->engineResponses->filter(function ($engineResponse) use ($transactionKey) {
                return $engineResponse->getTransactionKey() === $transactionKey && $engineResponse->getCreatedByEngineAt() !== null;
            });

            $responses->sort(function ($a, $b) {
                return $a->getCreatedByEngineAt()->getTimestamp() <=> $b->getCreatedByEngineAt()->getTimestamp();
            });

            $transactions[] = new TransactionState(
                $responses->first(),
                $responses
            );
        }

        return $this->setRelated($transactions);
    }

    /**
     * Set related transactions to the main transaction
     *
     * @param array $transactions
     *
     * @return array
     */
    private function setRelated(array $transactions): array
    {
        foreach ($transactions as $transaction) {
            $transaction->setRelated($this->getRelatedTransactions($transaction, $transactions));
        }

        return $transactions;
    }

    /**
     * Get all related transactions 
     *
     * @param TransactionState $transaction
     * @param array $transactions
     *
     * @return array
     */
    private function getRelatedTransactions(TransactionState $transaction, array $transactions): array
    {
        $related = [];
        foreach ($transactions as $tr) {
            if ($tr->getLatestResponse()->getRelatedTransaction() === $transaction->getLatestResponse()->getTransactionKey()) {
                $related[] = $tr;
            }
        }

        return $related;
    }

    /**
     * Has refunds
     *
     * @return boolean
     */
    public function hasRefunds(): bool
    {
        return count($this->getRefunds()) > 0;
    }

    /**
     * Has cancellations
     *
     * @return boolean
     */
    public function isGroup(): bool
    {
        return count($this->getCancellations()) > 0;
    }

    /**
     * Has authorization
     *
     * @return boolean
     */
    public function hasAuthorization(): bool {
        return $this->getAuthorization() !==  null;
    }

    /**
     * Get successful refunds
     *
     * @return array
     */
    public function getRefunds(): array
    {
        return $this->getSuccessful(
            $this->getOfType(RequestType::REFUND)
        );
    }

    /**
     * Get successful canceled transactions
     *
     * @return array
     */
    public function getCancellations(): array
    {
        return $this->getSuccessful(
            $this->getOfType(RequestType::CANCEL)
        );
    }

    /**
     * Get successful payments
     *
     * @return array
     */
    public function getPayments(): array
    {
        return $this->getSuccessful(
            $this->getOfTypes([
                RequestType::PAYMENT,
                RequestType::GIFTCARD,
            ])
        );
    }

    /**
     * Get successful authorization
     *
     * @return EngineResponseEntity|null
     */
    public function getAuthorization(): ?EngineResponseEntity
    {
        return array_pop($this->getSuccessful(
            $this->getOfType(RequestType::AUTHORIZE),
        ));
    }

    private function getOfType(string $type): array
    {
        return $this->getOfTypes([$type]);
    }

    private function getOfTypes(array $types): array
    {
        $transactions = [];
        foreach ($this->transactions as $transaction) {
            if (in_array($transaction->getLatestResponse()->getType(), $types)) {
                $transactions[] = $transaction;
            }
        }
        return $transactions;
    }

    private function getSuccessful(
        array $transactions,
    ): array {

        $transactions = [];
        foreach ($this->transactions as $transaction) {
            if ($transaction->getLatestResponse()->getAction() === RequestStatus::SUCCESS) {
                $transactions[] = $transaction;
            }
        }
        return $transactions;
    }
}
