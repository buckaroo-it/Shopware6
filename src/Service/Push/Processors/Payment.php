<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors;

use Buckaroo\Shopware6\Service\Push\PaymentStatus;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Service\Push\Processors\StatusProcessorInterface;
use Buckaroo\Shopware6\Service\Push\Transaction;
use Buckaroo\Shopware6\Service\Push\TypeFactory;

class Payment extends AbstractProcessor implements StatusProcessorInterface
{
    public const TYPE = TypeFactory::TYPE_PAYMENT;

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
