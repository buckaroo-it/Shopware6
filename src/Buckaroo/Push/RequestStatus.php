<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Resources\Constants\ResponseStatus;

class RequestStatus
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function fromStatusCode(string $statusCode): string
    {
        $status = null;
        if ($statusCode == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            $status = self::STATUS_SUCCESS;
        }

        if (in_array(
            $statusCode,
            [
                ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR,
                ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE,
                ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT,
                ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                ResponseStatus::BUCKAROO_STATUSCODE_REJECTED
            ]
        )) {
            $status = self::STATUS_FAILED;
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER) {
            $status = self::STATUS_CANCELLED;
        }

        if (in_array(
            $statusCode,
            [
                ResponseStatus::BUCKAROO_STATUSCODE_WAITING_ON_CONSUMER,
                ResponseStatus::BUCKAROO_STATUSCODE_WAITING_ON_USER_INPUT,
                ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING,
                ResponseStatus::BUCKAROO_STATUSCODE_PAYMENT_ON_HOLD
            ]
        )) {
            $status = self::STATUS_PENDING;
        }

        return $status;
    }
}
