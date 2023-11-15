<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class Payment extends AbstractProcessor implements StatusProcessorInterface
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->addOrderData(
            [
                "serviceCode" => $state->getRequest()->getServiceCode(),
                "transactionKey" => $state->getRequest()->getTransactionKey(),
                "isTest" => $state->getRequest()->isTest(),
            ]
        );
    }
}
