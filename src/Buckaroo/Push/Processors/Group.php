<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;

class Group extends Payment
{
    public const TYPE = RequestType::GROUP;

    public function onSuccess(ProcessingStateInterface $state): void
    {
        parent::onSuccess($state);
        $state->setStatus(null);
        $state->setTransaction(null);
    }
}
