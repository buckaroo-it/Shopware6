<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config;

use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PageFactory
{

    protected array $pageProvider;

    protected SettingsService $settingsService;

    protected CheckoutFactory $checkoutFactory;

    public function __construct(
        CheckoutFactory $checkoutFactory,
        SettingsService $settingsService,
        array $pageProvider
    ) {
        $this->checkoutFactory = $checkoutFactory;
        $this->pageProvider = $pageProvider;
        $this->settingsService = $settingsService;
    }

    public function get(SalesChannelContext $salesChannelContext, string $page = 'checkout'): BuckarooStruct
    {
        $config = $this->getProviderConfig(
            new State($salesChannelContext, $this->settingsService, $page)
        );


        if ($page === 'checkout') {
            $config = array_merge($config, $this->checkoutFactory->get($salesChannelContext));
        }
        $struct = new BuckarooStruct();
        $struct->assign($config);
        return $struct;
    }

    private function getProviderConfig(State $state): array
    {
        $config  = [];
        foreach ($this->pageProvider as $provider) {
            if (
                $provider instanceof ConfigInterface
            ) {
                $config = array_merge($config, $provider->get($state));
            }
        }

        return $config;
    }
}
