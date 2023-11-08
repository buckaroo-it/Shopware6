<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\RequestType;

class ProcessingState implements ProcessingStateInterface
{
    private Request $request;

    private ?string $status = RequestStatus::SKIP;

    private array $orderData = [];

    private string $type;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setStatus(string $status = null): void
    {
        $this->status = $status;
    }

    public function addOrderData(array $data): void
    {
        $this->orderData = array_merge_recursive(
            $this->orderData,
            $data
        );
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getTransactionData(): array
    {
        return [
            "type" => $this->type,
            "transaction" => $this->request->getTransactionKey(),
            "transactionType" => $this->request->getTransactionType(),
            "relatedTransaction" => $this->request->getRelatedTransaction(),
            "serviceCode" => $this->request->getServiceCode(),
            "statusCode" => $this->request->getStatusCode(),
            "status" => $this->status ?? $this->request->getStatus(),
            "amount" => $this->getAmount(),
            "isTest" => $this->request->isTest(),
            "createdByEngineAt" => $this->request->getCreatedAt(),
            "signature" => $this->request->getSignature()
        ];
    }

 

     /**
     * Get credit or debit amount
     *
     * @return float
     */
    private function getAmount(): float
    {
        if ($this->type === RequestType::REFUND) {
            return $this->request->getCreditAmount();
        }
        return $this->request->getDebitAmount();
    }
}
