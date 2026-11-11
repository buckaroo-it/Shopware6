<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\ErrorReporting;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler on the plugin's own "buckaroo_payment" channel: passes
 * error/critical records that carry an exception to the ErrorReporter.
 *
 * It never stops the record from reaching the other handlers (the log file).
 */
class ErrorReportingHandler extends AbstractHandler
{
    private ErrorReporter $reporter;

    public function __construct(ErrorReporter $reporter, int|string|Level $level = Level::Error)
    {
        parent::__construct($level, true);
        $this->reporter = $reporter;
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        try {
            $this->reporter->reportLog($record->level->toPsrLogLevel(), $record->message, $record->context);
        } catch (\Throwable $th) {
            // Never let error reporting break the shop.
        }

        return false;
    }
}
