<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;
use Buckaroo\Shopware6\Buckaroo\Push\PaymentStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class Giftcard extends AbstractProcessor implements StatusProcessorInterface
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_PAID);
        $state->setTransaction(new Transaction($state), RequestType::GIFTCARD);
    }
}
