<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

class RequestType
{
    public const PAYMENT = 'payment';
    public const GROUP = 'group';
    public const AUTHORIZE = 'authorize';
    public const REFUND = 'refund';
    public const CANCEL = 'cancel';
    public const GIFTCARD = 'giftcard';
}