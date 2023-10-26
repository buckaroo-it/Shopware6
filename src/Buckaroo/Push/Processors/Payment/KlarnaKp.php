<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors\Payment;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;
use Buckaroo\Shopware6\Buckaroo\Push\PaymentStatus;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\Payment;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;

class KlarnaKp extends Payment
{
    public function onSuccess(ProcessingStateInterface $state): void
    {
        parent::onSuccess($state);
        $state->addOrderData(
            ["klarnaReservationNumber" => $state->getRequest()->getString('brq_SERVICE_klarnakp_ReservationNumber')]
        );

        if ($state->getRequest()->isDataRequest()) {
            $state->setTransaction(new Transaction($state), RequestType::AUTHORIZE);
            $state->setStatus(PaymentStatus::STATUS_AUTHORIZED);
        }
    }
}
