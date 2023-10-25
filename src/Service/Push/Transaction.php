<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;

class Transaction
{

    private ProcessingStateInterface $state;
    private string $type;

    public function __construct(ProcessingStateInterface $state, string $type = null)
    {
        if ($type === null) {
            $type = TypeFactory::TYPE_PAYMENT;
        }
        $this->type = $type;
        $this->state = $state;
    }

    /**
     * Get transaction data ready to be saved
     *
     * @return void
     */
    public function getData()
    {
        $request = $this->state->getRequest();
        return [
            "type" => $this->type,
            "transaction" => $request->getTransactionKey(),
            "transactionType" => $request->getTransactionType(),
            "relatedTransaction" => $request->getRelatedTransaction(),
            "serviceCode" => $request->getServiceCode(),
            "statusCode" => $request->getStatusCode(),
            "status" => $request->getStatus(),
            "amount" => $this->getAmount(),
            "isTest" => $request->isTest(),
            "createdByEngineAt" => $request->getCreatedAt(),
        ];
    }

    private function getAmount(): float
    {
        if ($this->type === TypeFactory::TYPE_REFUND) {
            return $this->state->getRequest()->getCreditAmount();
        }
        return $this->state->getRequest()->getDebitAmount();
    }
}
