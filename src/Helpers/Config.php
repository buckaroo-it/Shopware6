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
    public function websiteKey()
    {
        return $this->settingsService->getSetting('websiteKey');
    }

    /**
     * @return string
     */
    public function secretKey()
    {
        return $this->settingsService->getSetting('secretKey');
    }

    /**
     * @return string
     */
    public function guid()
    {
        return $this->settingsService->getSetting('guid');
    }

}
