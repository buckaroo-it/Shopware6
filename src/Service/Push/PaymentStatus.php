<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

class PaymentStatus
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED ='refunded';
    public const STATUS_FAIL_REFUND ='refund_fail';
    public const STATUS_AUTHORIZED = 'authorized';
}
