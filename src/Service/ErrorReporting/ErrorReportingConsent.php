<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\SettingsService;

/**
 * The merchant's (opt-in) decision on sharing plugin error reports with Buckaroo.
 *
 * Stored as a tri-state in the global (all sales channels) plugin configuration:
 * "not_asked" until the merchant answers the prompt in the administration,
 * then "yes" or "no".
 */
class ErrorReportingConsent
{
    public const CONFIG_KEY = 'errorReportingConsent';

    public const NOT_ASKED = 'not_asked';

    public const YES = 'yes';

    public const NO = 'no';

    public const STATES = [self::NOT_ASKED, self::YES, self::NO];

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Current state; anything unknown or unreadable counts as "not asked".
     */
    public function getState(): string
    {
        try {
            $value = $this->settingsService->getSettingAsString(self::CONFIG_KEY);
        } catch (\Throwable $th) {
            return self::NOT_ASKED;
        }

        return in_array($value, self::STATES, true) ? $value : self::NOT_ASKED;
    }

    public function isGranted(): bool
    {
        return $this->getState() === self::YES;
    }
}
