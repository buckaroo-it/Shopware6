<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors;

use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Service\Push\TypeFactory;

class Group extends Payment
{
    public const TYPE = TypeFactory::TYPE_GROUP;

    public function onSuccess(ProcessingStateInterface $state): void
    {
        parent::onSuccess($state);
        $state->setStatus(null);
        $state->setTransaction(null);
    }
}
