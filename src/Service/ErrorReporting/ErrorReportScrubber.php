<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\ErrorReporting;

/**
 * Client-side scrubbing of error reports, as defense in depth: the collector
 * scrubs as well, but personal or card data should never leave the shop.
 */
class ErrorReportScrubber
{
    public const FILTERED = '[FILTERED]';

    public const EMAIL = '[EMAIL]';

    public const IBAN = '[IBAN]';

    public const CARD = '[CARD]';

    private const SENSITIVE_KEY = '/passw(?:or)?d|secret|token|api[_\-]?key|authorization/i';

    /**
     * "password=...", "secret: ...", "'api_key' => '...'", "\"token\":\"...\""
     */
    private const SENSITIVE_PAIR = '/(["\']?)'
        . '([\w\-]*(?:passw(?:or)?d|secret|token|api[_\-]?key|authorization)[\w\-]*)\1'
        . '(\s*(?:=>|[:=])\s*)(["\']?)([^"\'\s,;&}\]]+)\4/i';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    private const IBAN_PATTERN = '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?\b/';

    /**
     * 13 to 19 digits, optionally separated by single spaces or dashes.
     */
    private const CARD_PATTERN = '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/';

    public function scrubString(string $value): string
    {
        $patterns = [
            self::SENSITIVE_PAIR => '$1$2$1$3$4' . self::FILTERED . '$4',
            self::EMAIL_PATTERN => self::EMAIL,
            self::IBAN_PATTERN => self::IBAN,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $scrubbed = preg_replace($pattern, $replacement, $value);
            if (!is_string($scrubbed)) {
                // A regex failure (e.g. invalid UTF-8) must not leak the raw value.
                return self::FILTERED;
            }
            $value = $scrubbed;
        }

        $scrubbed = preg_replace_callback(
            self::CARD_PATTERN,
            static fn (array $match): string => self::passesLuhn($match[0]) ? self::CARD : $match[0],
            $value
        );

        return is_string($scrubbed) ? $scrubbed : self::FILTERED;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    public function scrubArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::FILTERED;
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->scrubArray($value);
                continue;
            }
            $result[$key] = is_string($value) ? $this->scrubString($value) : $value;
        }

        return $result;
    }

    public function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    /**
     * Check a digit string with the Luhn checksum that card numbers use,
     * so order ids and timestamps are not masked as cards
     *
     * @param string $number
     * @return bool
     */
    private static function passesLuhn(string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
