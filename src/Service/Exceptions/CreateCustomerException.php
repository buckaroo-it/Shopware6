<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class CreateCustomerException extends \Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "Failed to create customer", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new CreateCustomerException for missing sales channel context
     *
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingSalesChannelContext(\Throwable $previous = null): self
    {
        return new self("SalesChannelContext is required", 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for invalid email
     *
     * @param string $email
     * @param \Throwable|null $previous
     * @return self
     */
    public static function invalidEmail(string $email, \Throwable $previous = null): self
    {
        return new self("Invalid email address: {$email}", 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for duplicate customer
     *
     * @param string $email
     * @param \Throwable|null $previous
     * @return self
     */
    public static function duplicateCustomer(string $email, \Throwable $previous = null): self
    {
        return new self("Customer with email '{$email}' already exists", 0, $previous);
    }

    /**
     * Create a new CreateCustomerException for missing required fields
     *
     * @param array $missingFields
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingRequiredFields(array $missingFields, \Throwable $previous = null): self
    {
        $fields = implode(', ', $missingFields);
        return new self("Missing required customer fields: {$fields}", 0, $previous);
    }
}
