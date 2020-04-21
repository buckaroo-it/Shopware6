<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers\Constants;

class ResponseStatus
{
    const BUCKAROO_STATUSCODE_SUCCESS               = '190';
    const BUCKAROO_STATUSCODE_FAILED                = '490';
    const BUCKAROO_STATUSCODE_VALIDATION_FAILURE    = '491';
    const BUCKAROO_STATUSCODE_TECHNICAL_ERROR       = '492';
    const BUCKAROO_STATUSCODE_REJECTED              = '690';
    const BUCKAROO_STATUSCODE_WAITING_ON_USER_INPUT = '790';
    const BUCKAROO_STATUSCODE_PENDING_PROCESSING    = '791';
    const BUCKAROO_STATUSCODE_WAITING_ON_CONSUMER   = '792';
    const BUCKAROO_STATUSCODE_PAYMENT_ON_HOLD       = '793';
    const BUCKAROO_STATUSCODE_CANCELLED_BY_USER     = '890';
    const BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT = '891';
    
	const BUCKAROO_AUTHORIZE_TYPE_CANCEL  = 'I014';
    const BUCKAROO_AUTHORIZE_TYPE_ACCEPT  = 'I013';
}
