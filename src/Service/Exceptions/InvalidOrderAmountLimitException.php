<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A minimum / maximum order amount setting that cannot be saved. The error codes are
 * translated by the administration (buckaroo-payment.orderAmountLimit.*).
 */
class InvalidOrderAmountLimitException extends HttpException
{
    public const INVALID_AMOUNT = 'BUCKAROO__ORDER_AMOUNT_LIMIT_INVALID';
    public const INVALID_RANGE = 'BUCKAROO__ORDER_AMOUNT_LIMIT_RANGE';

    public static function invalidAmount(string $configKey, ?string $salesChannelId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_AMOUNT,
            'The order amount limit {{ key }} must be empty or a positive amount.',
            ['key' => $configKey, 'salesChannelId' => $salesChannelId]
        );
    }

    public static function invalidRange(string $paymentCode, ?string $salesChannelId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_RANGE,
            'The minimum order amount of {{ paymentCode }} must be lower than the maximum order amount.',
            ['paymentCode' => $paymentCode, 'salesChannelId' => $salesChannelId]
        );
    }
}
