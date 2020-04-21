<?php

namespace Buckaroo\Shopware6\Helpers\Constants;

class ResponseStatus
{
	const SUCCESS = 190;
	const FAILED  = 490;
	const VALIDATION_FAILURE = 491;
	const TECHNICAL_FAILURE = 492;
	const CANCELLED_BY_USER = 890;
	const CANCELLED_BY_MERCHANT = 891;
	const REJECTED = 690;
	const PENDING_INPUT = 790;
	const PENDING_PROCESSING = 791;
	const AWAITING_CONSUMER = 792;

	// S001 => Transaction successfully processed
	// S122 => The transaction is non-refundable.
}
