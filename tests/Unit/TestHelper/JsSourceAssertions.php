<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\TestHelper;

/**
 * Checks JavaScript source for a piece of code regardless of its formatting.
 *
 * The JavaScript is formatted by Prettier (shopware-cli extension format), which decides
 * whitespace, line breaks, quote style and trailing commas. These are ignored in the comparison.
 */
trait JsSourceAssertions
{
    private function assertJsContains(string $code, string $source, string $message = ''): void
    {
        $this->assertStringContainsString(self::normalizeJs($code), self::normalizeJs($source), $message);
    }

    private static function normalizeJs(string $source): string
    {
        $source = str_replace("'", '"', $source);
        $source = (string)preg_replace('/\s+/', '', $source);

        return (string)preg_replace('/,([)\]}])/', '$1', $source);
    }
}
