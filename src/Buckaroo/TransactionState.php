<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseEntity;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;

class TransactionState
{

    protected EngineResponseCollection $engineResponses;

    protected EngineResponseEntity $latestResponse;

    protected array $relatedTransactions;

    public function __construct(
        EngineResponseEntity $latestResponse,
        EngineResponseCollection $engineResponses
    ) {
        $this->engineResponses = $engineResponses;
        $this->latestResponse = $latestResponse;
    }

    public function getRelated(): array
    {
        return $this->relatedTransactions;
    }

    public function setRelated(array $relatedTransactions): void
    {
        $this->relatedTransactions = $relatedTransactions;
    }

    public function getLatestResponse(): EngineResponseEntity
    {
        return $this->latestResponse;
    }

    public function getEngineResponses(): EngineResponseCollection
    {
        return $this->engineResponses;
    }
    //todo: implement getters

}
