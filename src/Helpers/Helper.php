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
    public function initializeBkr(): BkrClient
    {
        return $this->bkrClient;
    }

    public function getEnvironment($method = '')
    {
        return $this->settingsService->getSetting($method . 'Environment');
    }

    public function getSettingsValue($name)
    {
        return $this->settingsService->getSetting($name);
    }

    public function getGlobals(): Request
    {
        return new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER);
    }
}
