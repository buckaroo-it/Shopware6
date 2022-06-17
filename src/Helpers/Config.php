<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\Service\SettingsService;

class Config
{
    /** @var SettingsService $settingsService */
    private $settingsService;

    /**
     * Helper constructor.
     * @param SettingsService $settingsService
     */
    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * @return string
     */
    public function websiteKey(string $salesChannelId = null)
    {
        return $this->settingsService->getSetting('websiteKey', $salesChannelId);
    }

    /**
     * @return string
     */
    public function secretKey(string $salesChannelId = null)
    {
        return $this->settingsService->getSetting('secretKey', $salesChannelId);
    }

    /**
     * @return string
     */
    public function guid(string $salesChannelId = null)
    {
        return $this->settingsService->getSetting('guid', $salesChannelId);
    }

}
