<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\SavedTransactionState;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseRepository;

class EngineResponseService
{

    private EngineResponseRepository $engineResponseRepo;

    public function __construct(
        EngineResponseRepository $engineResponseRepo,
    ) {
        $this->engineResponseRepo = $engineResponseRepo;
    }

    public function saveEngineResponse(array $response, Context $context): void
    {
        $this->engineResponseRepo->upsert($response, $context);
    }

    public function getState(
        ?string $orderTransactionId,
        ?string $transactionKey,
        ?string $relatedKey,
        Context $context
    ) {
        return new SavedTransactionState(
            $this->engineResponseRepo->findByData(
                $orderTransactionId,
                $transactionKey,
                $relatedKey,
                $context
            )
        );
    }
}
