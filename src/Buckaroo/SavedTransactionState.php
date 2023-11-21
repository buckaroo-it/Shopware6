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
            $responses = $this->engineResponses->filter(function($engineResponse) use($transactionKey) {
                return $engineResponse->getTransactionKey() === $transactionKey;
            });

            $responses->sort(function($a, $b) {
                return $a->getCreatedAt()->getTimestamp() <=> $b->getCreatedAt()->getTimestamp();
            });

            $transactions[] = new TransactionState(
                $responses->first(),
                $responses
            );
        }

        return $transactions;
    }


    // /**
    //  * Has confirmed refunds
    //  *
    //  * @return boolean
    //  */
    // public function hasRefunds(): bool
    // {
    //     return count($this->getRefunds()) > 0;
    // }

    // /**
    //  * Has confirmed cancellations
    //  *
    //  * @return boolean
    //  */
    // public function isGroup(): bool
    // {
    //     return count($this->getCancellations()) > 0;
    // }



    // /**
    //  * Get successful refunds
    //  *
    //  * @return EngineResponseCollection
    //  */
    // public function getRefunds(): EngineResponseCollection
    // {
    //     return $this->getSuccessful(
    //         $this->getOfType(RequestType::REFUND)
    //     );
    // }

    // /**
    //  * Get successful canceled transactions
    //  *
    //  * @return EngineResponseCollection
    //  */
    // public function getCancellations(): EngineResponseCollection
    // {
    //     return $this->getSuccessful(
    //         $this->getOfType(RequestType::CANCEL)
    //     );
    // }

    // /**
    //  * Get successful payments
    //  *
    //  * @return EngineResponseCollection
    //  */
    // public function getPayments(): EngineResponseCollection
    // {
    //     return $this->getSuccessful(
    //         $this->getOfTypes([
    //             RequestType::PAYMENT,
    //             RequestType::GIFTCARD,
    //         ])
    //     );
    // }

    // /**
    //  * Get successful authorizations
    //  *
    //  * @return EngineResponseEntity|null
    //  */
    // public function getAuthorization(): ?EngineResponseEntity
    // {
    //     return $this->getSuccessful(
    //             $this->getOfType(RequestType::AUTHORIZE),
    //     )->first();
    // }

    // private function getOfType(string $type): EngineResponseCollection
    // {
    //     return $this->getOfTypes([$type]);
    // }

    // private function getOfTypes(array $types): EngineResponseCollection
    // {
    //     return $this->transactions->filter(
    //         static function (EngineResponseEntity $transaction) use ($types) {
    //             return in_array($transaction->getType(), $types);
    //         }
    //     );
    // }

    // private function getSuccessful(
    //     EngineResponseCollection $transactions,
    // ) {
    //     return $transactions->filter(
    //         static function (EngineResponseEntity $transaction) {
    //             return $transaction->getAction() === RequestStatus::SUCCESS;
    //         }
    //     );
    // }
}
