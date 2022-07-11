<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;

class Helper
{
    /** @var SettingsService $settingsService */
    private $settingsService;

    /** @var BkrClient $bkrClient */
    private $bkrClient;

    /**
     * Helper constructor.
     * @param SettingsService $settingsService
     * @param Api $client
     */
    public function __construct(SettingsService $settingsService, BkrClient $client)
    {
        $this->settingsService = $settingsService;
        $this->bkrClient = $client;
    }

    /**
     * @return BkrClient
     */
    public function initializeBkr(string $salesChannelId = null): BkrClient
    {
        $this->bkrClient->setSalesChannelId($salesChannelId);
        return $this->bkrClient;
    }

    public function getEnabled($method = '', $salesChannelId = null)
    {
        return $this->settingsService->getSetting($method . 'Enabled', $salesChannelId);
    }

    public function getEnvironment($method = '', $salesChannelId = null)
    {
        return $this->settingsService->getSetting($method . 'Environment', $salesChannelId);
    }

    public function getSettingsValue($name, $salesChannelId = null)
    {
        return $this->settingsService->getSetting($name, $salesChannelId);
    }

    public function getGlobals(): Request
    {
        return new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER);
    }
    public function getShopName(string $salesChannelId = null)
    {
        return $this->settingsService->getShopName($salesChannelId);
    }
}
