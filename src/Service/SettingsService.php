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
    public function getSetting(string $setting, ?string $salesChannelId = null)
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

        $label = preg_replace('/\{order_number\}/', (string)$order->getOrderNumber(), $label);
        $label = preg_replace('/\{shop_name\}/', $shopName, (string)$label);

        $products = $order->getLineItems();

        if ($products !== null) {

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity */
            $firstProduct = $products->first();
            if ($firstProduct !== null) {
                $label = preg_replace('/\{product_name\}/', $firstProduct->getLabel(), (string)$label);
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

    /**
     * Get the raw fee value (which may include % symbol)
     *
     * @param string $buckarooKey
     * @param string|null $salesChannelId
     * @return string
     */
    public function getBuckarooFeeRaw(string $buckarooKey, string $salesChannelId = null): string
    {
        $buckarooFee = $this->getSetting($buckarooKey . 'Fee', $salesChannelId);
        if (is_scalar($buckarooFee)) {
            return trim((string)$buckarooFee);
        }
        return '';
    }

    /**
     * Check if the fee is a percentage
     *
     * @param string $buckarooKey
     * @param string|null $salesChannelId
     * @return bool
     */
    public function isBuckarooFeePercentage(string $buckarooKey, string $salesChannelId = null): bool
    {
        $feeRaw = $this->getBuckarooFeeRaw($buckarooKey, $salesChannelId);
        return strpos($feeRaw, '%') !== false;
    }

    /**
     * Calculate the actual fee amount based on cart/order total
     * Handles both fixed amounts and percentage-based fees
     *
     * @param string $buckarooKey
     * @param float $orderTotal
     * @param string|null $salesChannelId
     * @return float
     */
    public function calculateBuckarooFee(string $buckarooKey, float $orderTotal, string $salesChannelId = null): float
    {
        $feeRaw = $this->getBuckarooFeeRaw($buckarooKey, $salesChannelId);
        
        if (empty($feeRaw)) {
            return 0;
        }

        // Check if it's a percentage fee
        if ($this->isBuckarooFeePercentage($buckarooKey, $salesChannelId)) {
            // Extract percentage value and calculate
            $percentageValue = (float)str_replace(['%', ',', ' '], ['', '.', ''], $feeRaw);
            return round(($orderTotal * $percentageValue) / 100, 2);
        }

        // It's a fixed fee
        return $this->getBuckarooFee($buckarooKey, $salesChannelId);
    }

    public function getEnabled(string $method = '', string $salesChannelId = null): bool
    {
        return $this->getSetting($method . 'Enabled', $salesChannelId) != 0;
    }
}
