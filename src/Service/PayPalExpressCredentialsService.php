<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

/**
 * Central place for resolving the PayPal Express credentials based on the
 * configured PayPal environment (live / test-sandbox).
 *
 * Only the merchant id is merchant specific. The PayPal (sandbox/live)
 * client ids are managed by the Buckaroo Client SDK itself: the storefront
 * signals the environment (isTestMode / Base.setTestMode) and the SDK
 * selects the matching client ids internally, consistent with the
 * implementation used in the other Buckaroo plugins.
 */
class PayPalExpressCredentialsService
{
    public const SETTING_ENVIRONMENT_METHOD = 'paypal';

    public const SETTING_LIVE_MERCHANT_ID = 'paypalExpressmerchantid';
    public const SETTING_SANDBOX_MERCHANT_ID = 'paypalExpressSandboxMerchantId';

    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Determine if PayPal is configured to run against the sandbox (test) environment.
     *
     * @param string|null $salesChannelId
     *
     * @return bool
     */
    public function isTestMode(?string $salesChannelId = null): bool
    {
        return $this->settingsService->getEnvironment(
            self::SETTING_ENVIRONMENT_METHOD,
            $salesChannelId
        ) !== 'live';
    }

    /**
     * Get the environment aware PayPal Express merchant id.
     * Live mode  -> live merchant id (existing setting, unchanged behavior).
     * Test mode  -> sandbox merchant id only, never the live one.
     *
     * @param string|null $salesChannelId
     *
     * @return string|null
     */
    public function getMerchantId(?string $salesChannelId = null): ?string
    {
        if ($this->isTestMode($salesChannelId)) {
            return $this->getStringSetting(self::SETTING_SANDBOX_MERCHANT_ID, $salesChannelId);
        }

        return $this->getStringSetting(self::SETTING_LIVE_MERCHANT_ID, $salesChannelId);
    }

    /**
     * Get all environment aware PayPal Express credentials at once.
     *
     * @param string|null $salesChannelId
     *
     * @return array{merchantId: string|null, isTestMode: bool}
     */
    public function getCredentials(?string $salesChannelId = null): array
    {
        return [
            'merchantId' => $this->getMerchantId($salesChannelId),
            'isTestMode' => $this->isTestMode($salesChannelId),
        ];
    }

    /**
     * @param string $setting
     * @param string|null $salesChannelId
     *
     * @return string|null
     */
    private function getStringSetting(string $setting, ?string $salesChannelId = null): ?string
    {
        $value = $this->settingsService->getSetting($setting, $salesChannelId);
        if ($value !== null && is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        return null;
    }
}
