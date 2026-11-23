<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\TestHelper;

/**
 * Runs a callback with PHP warnings, notices and deprecations turned into exceptions.
 *
 * Deprecations raised by libraries through trigger_deprecation() (E_USER_DEPRECATED)
 * are handed to the previous handler, so they are reported as before.
 */
trait PhpNoticeGuard
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withoutPhpNotices(callable $callback): mixed
    {
        $previousHandler = null;
        $previousHandler = set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler): bool {
                if ($errno === E_USER_DEPRECATED) {
                    return is_callable($previousHandler)
                        ? (bool)$previousHandler($errno, $errstr, $errfile, $errline)
                        : true;
                }

                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
