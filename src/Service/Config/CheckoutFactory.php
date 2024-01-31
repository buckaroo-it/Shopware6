<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config;

use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\Payment\Common;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CheckoutFactory
{
    protected array $paymentConfigProviders;

    protected Common $commonConfig;

    protected SettingsService $settingsService;

    public function __construct(
        Common $commonConfig,
        SettingsService $settingsService,
        array $paymentConfigProviders
    ) {
        $this->paymentConfigProviders = $paymentConfigProviders;
        $this->commonConfig = $commonConfig;
        $this->settingsService = $settingsService;
    }

    public function get(SalesChannelContext $salesChannelContext): array
    {
        $state = new State($salesChannelContext, $this->settingsService, 'checkout');
        return array_merge(
            $this->commonConfig->get($state),
            $this->getActiveProviderConfig($state)
        );
    }

    /**
     * Get config for active payment method
     *
     * @param State $state
     *
     * @return array
     */
    private function getActiveProviderConfig(State $state): array
    {
        foreach ($this->paymentConfigProviders as $key => $provider) {
            if (
                $key === $state->getBuckarooKey() &&
                $provider instanceof ConfigInterface
            ) {
                return $provider->get($state);
            }
        }
        return [];
    }
}
