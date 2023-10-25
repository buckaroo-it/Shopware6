<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors;

use Buckaroo\Shopware6\Service\Push\Transaction;
use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Buckaroo\Shopware6\Service\Push\PaymentStatus;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Service\Push\Processors\StatusProcessorInterface;

class Giftcard extends AbstractProcessor implements StatusProcessorInterface
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_PAID);
        $state->setTransaction(new Transaction($state), TypeFactory::TYPE_GIFTCARD);
    }
}
