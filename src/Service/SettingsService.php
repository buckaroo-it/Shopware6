<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
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

    /**
     * @param string $setting
     * @param string|null $salesChannelId
     *
     * @return string
     */
    public function getSettingAsString(string $setting, string $salesChannelId = null)
    {
        $setting = $this->getSetting($setting, $salesChannelId);
        if (!is_scalar($setting)) {
            return '';
        }
        return (string)$setting;
    }

    /**
     *
     * @param string $setting
     * @param mixed $value
     * @param string|null $salesChannelId
     *
     * @return void
     */
    public function setSetting(string $setting, $value, string $salesChannelId = null): void
    {
        if (is_scalar($value) || is_array($value) || is_null($value)) {
            $this->systemConfigService->set('BuckarooPayments.config.' . $setting, $value, $salesChannelId);
        }
    }

    /**
     * Get shop name
     *
     * @param string|null $salesChannelId
     *
     * @return string
     */
    public function getShopName(string $salesChannelId = null): string
    {
        $shopName = $this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId);
        if (!is_string($shopName)) {
            return '';
        }
        return $shopName;
    }

     /**
     * Get the parsed label, we replace the template variables with the values
     *
     * @param \Shopware\Core\Checkout\Order\OrderEntity $order
     * @param string $salesChannelId
     * @param string $label
     *
     * @return string
     */
    public function getParsedLabel(OrderEntity $order, string $salesChannelId, string $label): string
    {
        $label = $this->getSettingAsString($label, $salesChannelId);
        $shopName = $this->getShopName($salesChannelId);
        if (empty($label)) {
            return $shopName;
        }

        $label = str_replace('{order_number}', (string)$order->getOrderNumber(), $label);
        $label = str_replace('{shop_name}', $shopName, (string)$label);

        $products = $order->getLineItems();

        if ($products !== null) {

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity */
            $firstProduct = $products->first();
            if ($firstProduct !== null) {
                $label = str_replace('{product_name}', $firstProduct->getLabel(), (string)$label);
            }
        }
        if (is_string($label)) {
            return mb_substr($label, 0, 244);
        }

        return $shopName;
    }

    /**
     *
     * @param string $method
     * @param string|null $salesChannelId
     *
     * @return string
     */
    public function getEnvironment(string $method = '', string $salesChannelId = null): string
    {
        return $this->getSettingAsString($method . 'Environment', $salesChannelId);
    }

    public function getBuckarooFee(string $buckarooKey, string $salesChannelId = null): float
    {
        $buckarooFee = $this->getSetting($buckarooKey . 'Fee', $salesChannelId);
        if (is_scalar($buckarooFee)) {
            return round((float)str_replace(',', '.', (string)$buckarooFee), 2);
        }
        return 0;
    }

    public function getEnabled(string $method = '', string $salesChannelId = null): bool
    {
        return $this->getSetting($method . 'Enabled', $salesChannelId) != 0;
    }

    public function getPaymentLabel(string $method = '', string $salesChannelId = null): string
    {
        return $this->getSettingAsString($method . 'Label', $salesChannelId);
    }
}
