<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class BuckarooPaymentRejectException extends \Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = "Payment was rejected by Buckaroo",
        int $code = 0,
        \Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for insufficient funds
     *
     * @param float $amount
     * @param \Throwable|null $previous
     * @return self
     */
    public static function insufficientFunds(float $amount, \Throwable $previous = null): self
    {
        return new self("Insufficient funds for payment amount: {$amount}", 0, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for invalid card
     *
     * @param string $reason
     * @param \Throwable|null $previous
     * @return self
     */
    public static function invalidCard(string $reason = "Card validation failed", \Throwable $previous = null): self
    {
        return new self("Invalid card: {$reason}", 0, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for expired card
     *
     * @param string $expiryDate
     * @param \Throwable|null $previous
     * @return self
     */
    public static function expiredCard(string $expiryDate, \Throwable $previous = null): self
    {
        return new self("Card expired on: {$expiryDate}", 0, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for fraud detection
     *
     * @param string $reason
     * @param \Throwable|null $previous
     * @return self
     */
    public static function fraudDetected(
        string $reason = "Fraud detection triggered",
        \Throwable $previous = null
    ): self
    {
        return new self("Fraud detected: {$reason}", 0, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for 3D Secure failure
     *
     * @param string $reason
     * @param \Throwable|null $previous
     * @return self
     */
    public static function threeDSecureFailed(
        string $reason = "3D Secure authentication failed",
        \Throwable $previous = null
    ): self
    {
        return new self("3D Secure failed: {$reason}", 0, $previous);
    }

    /**
     * Create a new BuckarooPaymentRejectException for bank rejection
     *
     * @param string $bankCode
     * @param string $reason
     * @param \Throwable|null $previous
     * @return self
     */
    public static function bankRejection(string $bankCode, string $reason, \Throwable $previous = null): self
    {
        return new self("Bank '{$bankCode}' rejected payment: {$reason}", 0, $previous);
    }
}
