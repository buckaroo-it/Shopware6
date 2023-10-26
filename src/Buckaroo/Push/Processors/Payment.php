<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;
use Buckaroo\Shopware6\Buckaroo\Push\PaymentStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class Payment extends AbstractProcessor implements StatusProcessorInterface
{
    public const TYPE = RequestType::PAYMENT;

    public function onSuccess(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_PAID);
        $state->addOrderData(
            [
                "serviceCode" => $state->getRequest()->getServiceCode(),
                "transactionKey" => $state->getRequest()->getTransactionKey()
            ]
        );
        $state->setTransaction(new Transaction($state), self::TYPE);
    }

    public function onFailed(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_FAILED);
    }

    public function onCancel(ProcessingStateInterface $state): void
    {
        $state->setStatus(PaymentStatus::STATUS_CANCELLED);
    }
}
