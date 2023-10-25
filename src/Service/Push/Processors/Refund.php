<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors;

use Buckaroo\Shopware6\Service\Push\PaymentStatus;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Service\Push\Processors\StatusProcessorInterface;
use Buckaroo\Shopware6\Service\Push\Transaction;
use Buckaroo\Shopware6\Service\Push\TypeFactory;

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
        $state->setTransaction(new Transaction($state, TypeFactory::TYPE_REFUND));
    }
}
