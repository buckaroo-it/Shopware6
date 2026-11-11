<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\ErrorReporting;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * Wraps the shop's logger for the plugin services that log there instead of on
 * the plugin's own channel. Everything is logged exactly as before; error and
 * worse records carrying an exception are also passed to the ErrorReporter.
 *
 * Only plugin services get this logger, so only plugin log calls are reported.
 */
class ReportingLogger implements LoggerInterface
{
    use LoggerTrait;

    private const REPORTED_LEVELS = [
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    private LoggerInterface $inner;

    private ErrorReporter $reporter;

    public function __construct(LoggerInterface $inner, ErrorReporter $reporter)
    {
        $this->inner = $inner;
        $this->reporter = $reporter;
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);

        if (!is_string($level) || !in_array($level, self::REPORTED_LEVELS, true)) {
            return;
        }

        try {
            $this->reporter->reportLog($level, (string) $message, $context);
        } catch (\Throwable $th) {
            // Never let error reporting break the shop.
        }
    }
}
