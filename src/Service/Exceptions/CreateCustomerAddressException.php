<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class CreateCustomerAddressException extends \Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "Failed to create customer address", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new CreateCustomerAddressException for missing sales channel context
     *
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingSalesChannelContext(\Throwable $previous = null): self
    {
        return new self("SalesChannelContext is required", 0, $previous);
    }

    /**
     * Create a new CreateCustomerAddressException for missing customer
     *
     * @param string $customerId
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingCustomer(string $customerId, \Throwable $previous = null): self
    {
        return new self("Customer '{$customerId}' not found for address creation", 0, $previous);
    }

    /**
     * Create a new CreateCustomerAddressException for invalid country
     *
     * @param string $countryCode
     * @param \Throwable|null $previous
     * @return self
     */
    public static function invalidCountry(string $countryCode, \Throwable $previous = null): self
    {
        return new self("Invalid country code: {$countryCode}", 0, $previous);
    }

    /**
     * Create a new CreateCustomerAddressException for missing required fields
     *
     * @param array $missingFields
     * @param \Throwable|null $previous
     * @return self
     */
    public static function missingRequiredFields(array $missingFields, \Throwable $previous = null): self
    {
        $fields = implode(', ', $missingFields);
        return new self("Missing required address fields: {$fields}", 0, $previous);
    }

    /**
     * Create a new CreateCustomerAddressException for invalid postal code
     *
     * @param string $postalCode
     * @param string $countryCode
     * @param \Throwable|null $previous
     * @return self
     */
    public static function invalidPostalCode(string $postalCode, string $countryCode, \Throwable $previous = null): self
    {
        return new self("Invalid postal code '{$postalCode}' for country '{$countryCode}'", 0, $previous);
    }
}
