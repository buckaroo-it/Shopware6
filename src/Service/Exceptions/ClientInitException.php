<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Exceptions;

class ClientInitException extends \Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = "Failed to initialize Buckaroo client", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a new ClientInitException for connection failures
     *
     * @param string $endpoint
     * @param \Throwable|null $previous
     * @return self
     */
    public static function connectionFailed(string $endpoint, \Throwable $previous = null): self
    {
        return new self("Failed to connect to Buckaroo endpoint: {$endpoint}", 0, $previous);
    }

    /**
     * Create a new ClientInitException for configuration errors
     *
     * @param string $configKey
     * @param \Throwable|null $previous
     * @return self
     */
    public static function configurationError(string $configKey, \Throwable $previous = null): self
    {
        return new self("Configuration error for key '{$configKey}': Invalid or missing configuration", 0, $previous);
    }

    /**
     * Create a new ClientInitException for authentication failures
     *
     * @param string $reason
     * @param \Throwable|null $previous
     * @return self
     */
    public static function authenticationFailed(string $reason = "Invalid credentials", \Throwable $previous = null): self
    {
        return new self("Authentication failed: {$reason}", 0, $previous);
    }
}
