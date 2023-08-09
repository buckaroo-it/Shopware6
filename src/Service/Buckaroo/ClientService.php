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
    public function get(string $configMethodCode, string $salesChannelId = null): Client
    {
        $mode = $this->settingsService->getEnvironment($configMethodCode, $salesChannelId);

        try {
            return new Client(
                $this->settingsService->getSettingAsString('websiteKey', $salesChannelId),
                $this->settingsService->getSettingAsString('secretKey', $salesChannelId),
                $this->getPaymentCode($configMethodCode, $salesChannelId),
                $mode  == 'live' ? Config::LIVE_MODE : Config::TEST_MODE
            );
        } catch (\Throwable $th) {
            throw new ClientInitException("Cannot initiate buckaroo sdk client", 0, $th);
        }
    }

    /**
     * Get code required for payment
     *
     * @param string $configCode
     * @param string $salesChannelId
     *
     * @return string
     */
    protected function getPaymentCode(string $configCode, string $salesChannelId = null): string
    {
        if (
            $configCode === 'afterpay' &&
            $this->settingsService->getSetting('afterpayEnabledold', $salesChannelId) === true
        ) {
            return 'afterpaydigiaccept';
        }

        $mappings = [
            'klarnain' => 'klarna',
            'capayable' => 'in3',
            'giftcards' => 'giftcard',
            'creditcards' => 'creditcard',
            'idealqr' => 'ideal_qr'
        ];
        if (isset($mappings[$configCode])) {
            return $mappings[$configCode];
        }

        return $configCode;
    }
}
