<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Buckaroo;

use Buckaroo\Config\Config;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\Exceptions\ClientInitException;
use Buckaroo\Shopware6\Service\SettingsService;

class ClientService
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Get buckaroo client
     *
     * @param string $configMethodCode
     * @param string $salesChannelId
     *
     * @return Client
     * @throws ClientInitException
     */
    public function get(string $configMethodCode, string $salesChannelId): Client
    {
        $mode = $this->settingsService->getEnvironment($configMethodCode, $salesChannelId) == 'live' ? Config::LIVE_MODE : Config::TEST_MODE;

        try {
            return new Client(
                $this->settingsService->getSetting('websiteKey', $salesChannelId),
                $this->settingsService->getSetting('secretKey', $salesChannelId),
                $mode
            );
        } catch (\Throwable $th) {
            throw new ClientInitException("Cannot initiate buckaroo sdk client", 0, $th);
        }
    }
}
