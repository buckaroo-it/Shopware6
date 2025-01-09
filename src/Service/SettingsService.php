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
    public function getSetting(string $setting, string $salesChannelId = null)
    {
        return $this->systemConfigService->get('BuckarooPayments.config.' . $setting, $salesChannelId);
    }

    public function setSetting(string $setting, $value, string $salesChannelId = null)
    {
        return $this->systemConfigService->set('BuckarooPayments.config.' . $setting, $value, $salesChannelId);
    }
    
    public function getShopName(string $salesChannelId = null)
    {
        return $this->systemConfigService->get('core.basicInformation.shopName');
    }

    public function getEnabled($method = '', $salesChannelId = null)
    {
        return $this->getSetting($method . 'Enabled', $salesChannelId);
    }
}
