<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors;

use Buckaroo\Shopware6\Service\Push\Transaction;
use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Buckaroo\Shopware6\Service\Push\PaymentStatus;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;


class Authorize extends AbstractProcessor
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setTransaction(new Transaction($state), TypeFactory::TYPE_AUTHORIZE);
        $state->setStatus(PaymentStatus::STATUS_AUTHORIZED);
    }
}
