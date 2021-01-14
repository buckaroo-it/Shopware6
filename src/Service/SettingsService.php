<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class SettingsService
{
    /**
     * @var SystemConfigService
     */
    public $systemConfigService;

    /**
     * SettingsService constructor.
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @param string $setting
     * @return mixed|null
     */
    public function getSetting(string $setting)
    {
        return $this->systemConfigService->get('BuckarooPayments.config.' . $setting);
    }

    public function setSetting(string $setting, $value)
    {
        return $this->systemConfigService->set('BuckarooPayments.config.' . $setting, $value);
    }
}
