<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;
use Buckaroo\Shopware6\Buckaroo\Push\PaymentStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class Refund extends AbstractProcessor implements StatusProcessorInterface
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_REFUNDED);
        $this->addTransaction($state);
    }

    public function onFailed(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_FAIL_REFUND);
        $this->addTransaction($state);
    }

    public function onCancel(ProcessingStateInterface $state): void
    {
        $this->onFailed($state);
    }

    private function addTransaction(ProcessingStateInterface $state): void
    {
        $state->setTransaction(new Transaction($state, RequestType::REFUND));
    }
}
