<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\AbstractProcessor;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class Giftcard extends AbstractProcessor implements StatusProcessorInterface
{
    public function onProcessing(ProcessingStateInterface $state): void
    {
        $state->setStatus(RequestStatus::SKIP);
    }
}
