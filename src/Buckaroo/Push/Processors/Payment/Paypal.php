<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors\Payment;

use Buckaroo\Resources\Constants\ResponseStatus;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\Payment;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;

class Paypal extends Payment
{
    public function onProcessing(ProcessingStateInterface $state): void
    {
        if ($state->getRequest()->getStatusCode() === ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        {
            $state->setStatus(RequestStatus::FAILED);
        }
    }
}
