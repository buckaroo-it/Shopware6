<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Helper;

use Buckaroo\Shopware6\API\BkrClient;
use Buckaroo\Shopware6\Service\SettingsService;

class ApiHelper
{
    /** @var SettingsService $settingsService */
    private $settingsService;
    /** @var BkrClient $bkrClient */
    private $bkrClient;

    /**
     * ApiHelper constructor.
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
    public function initializeBuckarooClient(): BkrClient
    {
        return $this->bkrClient;
    }

    public function getEnvironment(): string
    {
        return $this->settingsService->getSetting('environment');
    }
}
