<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push\Processors\Payment;

use Buckaroo\Resources\Constants\ResponseStatus;
use Buckaroo\Shopware6\Service\Push\Processors\Payment;
use Buckaroo\Shopware6\Service\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Service\Push\RequestStatus;

class Paypal extends Payment
{
    public function onProcessing(ProcessingStateInterface $state): void
    {
        if ($state->getRequest()->getStatusCode() === ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        {
            $state->setStatus(RequestStatus::STATUS_FAILED);
        }
    }
}
