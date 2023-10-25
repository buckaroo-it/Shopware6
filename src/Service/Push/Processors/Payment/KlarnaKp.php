<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors\Payment;

use Buckaroo\Shopware6\Service\Push\Transaction;
use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Buckaroo\Shopware6\Service\Push\PaymentStatus;
use Buckaroo\Shopware6\Service\Push\Processors\Payment;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;

class KlarnaKp extends Payment
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        parent::onSuccess($state);
        $state->addOrderData(
            ["klarnaReservationNumber" => $state->getRequest()->getString('brq_SERVICE_klarnakp_ReservationNumber')]
        );

        if ($state->getRequest()->isDataRequest()) {
            $state->setTransaction(new Transaction($state), TypeFactory::TYPE_AUTHORIZE);
            $state->setStatus(PaymentStatus::STATUS_AUTHORIZED);
        }
    }
}
