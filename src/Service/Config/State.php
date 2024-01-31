<?php

namespace Buckaroo\Shopware6\Service\Config;

use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class State
{
    private SalesChannelContext $salesChannelContext;

    private SettingsService $settingsService;

    private string $page;

    public function __construct(
        SalesChannelContext $salesChannelContext,
        SettingsService $settingsService,
        string $page = 'checkout'
    ) {
        $this->salesChannelContext = $salesChannelContext;
        $this->settingsService = $settingsService;
        $this->page = $page;
    }

    public function getBuckarooKey(): ?string
    {
        return $this->getBuckarooKeyByPayment($this->salesChannelContext->getPaymentMethod());
    }


    public function getBuckarooKeyByPayment(PaymentMethodEntity $paymentMethod): ?string
    {
        $translation = $paymentMethod->getTranslated();
        if (!isset($translation['customFields']) || !is_array($translation['customFields'])) {
            return null;
        }

        if (
            !isset($translation['customFields']['buckaroo_key']) ||
            !is_string($translation['customFields']['buckaroo_key'])
        ) {
            return null;
        }
        return $translation['customFields']['buckaroo_key'];
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelContext->getSalesChannelId();
    }

    public function getSalesChannel(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getSetting(string $name)
    {
        return $this->settingsService->getSetting($name, $this->getSalesChannelId());
    }

    public function getPaymentFee(string $buckarooKey = null): float
    {
        if ($buckarooKey === null) {
            $buckarooKey = $this->getBuckarooKey();
        }
        
        return $this->settingsService->getBuckarooFee($buckarooKey, $this->getSalesChannelId());
    }

    public function getPaymentLabel(string $buckarooKey): string
    {
        return $this->settingsService->getPaymentLabel($buckarooKey, $this->getSalesChannelId());
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->salesChannelContext->getCustomer();
    }

    public function getPage(): string
    {
        return $this->page;
    }
}
