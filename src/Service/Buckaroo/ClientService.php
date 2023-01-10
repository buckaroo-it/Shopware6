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
                $this->getPaymentCode($configMethodCode, $salesChannelId),
                $mode
            );
        } catch (\Throwable $th) {
            throw new ClientInitException("Cannot initiate buckaroo sdk client", 0, $th);
        }
    }

        /**
     * Get code required for payment
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return string
     */
    protected function getPaymentCode(string $paymentCode, string $salesChannelId): string
    {
        if(
            $paymentCode === 'afterpay' &&
            $this->settingsService->getSetting('afterpayEnabledold', $salesChannelId) === true
        ) {
            return 'afterpaydigiaccept';
        }

        if ($paymentCode === 'klarnain') {
            return 'klarna';
        }

        if($paymentCode === 'capayable') {
            return 'in3';
        }

        if($paymentCode === 'giftcards') {
            return 'giftcard';
        }

        return $paymentCode;
    }
}
