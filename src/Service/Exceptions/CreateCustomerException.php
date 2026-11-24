<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class CreateCustomerException extends \Exception
{
    public function __construct(
        string $message = 'Failed to create customer',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new CreateCustomerException for missing sales channel context
     */
    public static function missingSalesChannelContext(?\Throwable $previous = null): self
    {
        return new self('SalesChannelContext is required', 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for invalid email
     */
    public static function invalidEmail(string $email, ?\Throwable $previous = null): self
    {
        return new self("Invalid email address: {$email}", 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for duplicate customer
     */
    public static function duplicateCustomer(string $email, ?\Throwable $previous = null): self
    {
        return new self("Customer with email '{$email}' already exists", 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for missing required fields
     */
    public static function missingRequiredFields(array $missingFields, ?\Throwable $previous = null): self
    {
        $fields = implode(', ', $missingFields);

        return new self("Missing required customer fields: {$fields}", 0, $previous);
    }
}
