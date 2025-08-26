<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class CreateCartException extends \Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "Failed to create cart", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new CreateCartException for missing sales channel context
     *
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingSalesChannelContext(\Throwable $previous = null): self
    {
        return new self("SalesChannelContext is required", 0, $previous);
    }

    /**
     * Create a new CreateCartException for empty cart
     *
     * @param \Throwable|null $previous
     * @return self
     */
    public static function emptyCart(\Throwable $previous = null): self
    {
        return new self("Cannot create cart, at least one item is required", 0, $previous);
    }

    /**
     * Create a new CreateCartException for item addition failures
     *
     * @param string $itemId
     * @param \Throwable|null $previous
     * @return self
     */
    public static function itemAdditionFailed(string $itemId, \Throwable $previous = null): self
    {
        return new self("Cannot add item '{$itemId}' to cart", 0, $previous);
    }

    /**
     * Create a new CreateCartException for invalid customer
     *
     * @param string $customerId
     * @param \Throwable|null $previous
     * @return self
     */
    public static function invalidCustomer(string $customerId, \Throwable $previous = null): self
    {
        return new self("Invalid customer '{$customerId}' for cart creation", 0, $previous);
    }
}
