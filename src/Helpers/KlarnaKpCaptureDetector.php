<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * Recognises Klarna KP pushes reporting a reservation that was paid (captured)
 * outside of this plugin.
 *
 * A Klarna KP reservation can be paid without CaptureService ever running:
 *  - the Buckaroo engine pays it automatically (KlarnaKP AutoPay). The reserve push
 *    then carries brq_SERVICE_klarnakp_AutoPayTransactionKey and a separate V610 push
 *    carries brq_SERVICE_klarnakp_CaptureId;
 *  - a merchant pays the reservation manually in the Buckaroo Payment Plaza;
 *  - our own capture succeeded at Buckaroo but the response never reached Shopware.
 *
 * If those are not recorded, customFields['captured'] stays empty, the
 * capture-on-shipment guards in OrderStateChangeEvent stay satisfied forever, and
 * every shipped delivery retries a "Pay on reservation" that Buckaroo rejects with
 * statuscode 491 "reservation has status FullyCaptured". That validation failure is
 * pushed back to PushController, where it can move an already refunded transaction
 * to cancelled.
 */
final class KlarnaKpCaptureDetector
{
    public const AUTO_PAY_TRANSACTION_KEY = 'brq_SERVICE_klarnakp_AutoPayTransactionKey';

    public const CAPTURE_ID = 'brq_SERVICE_klarnakp_CaptureId';

    /**
     * Whether this push reports a Klarna KP reservation that the payment engine has
     * already paid. Only meaningful for a successful push.
     */
    public static function isEngineCapture(Request $request): bool
    {
        if (!self::isKlarnaKp($request)) {
            return false;
        }

        if (!empty($request->request->get(self::AUTO_PAY_TRANSACTION_KEY))) {
            return true;
        }

        if (!empty($request->request->get(self::CAPTURE_ID))) {
            return true;
        }

        return (string)$request->request->get('brq_transaction_type')
            === ResponseStatus::BUCKAROO_KLARNAKP_PAY_TYPE;
    }

    private static function isKlarnaKp(Request $request): bool
    {
        // The reserve/datarequest push identifies the method through
        // brq_primary_service, the financial pushes through brq_transaction_method.
        $method = $request->request->get('brq_primary_service')
            ?: $request->request->get('brq_transaction_method');

        return is_string($method) && strtolower($method) === 'klarnakp';
    }
}
