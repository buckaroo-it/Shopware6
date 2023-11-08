<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;

class Group extends Payment
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setStatus(RequestStatus::SKIP);
    }
}
