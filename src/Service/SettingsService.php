<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

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

    public function setSetting(string $setting, $value, string $salesChannelId = null)
    {
        return $this->systemConfigService->set('BuckarooPayments.config.' . $setting, $value, $salesChannelId);
    }
    public function getShopName(string $salesChannelId = null)
    {
        return $this->systemConfigService->get('core.basicInformation.shopName',  $salesChannelId);
    }

     /**
     * Get the parsed label, we replace the template variables with the values
     *
     * @param Store $store
     * @param \Shopware\Core\Checkout\Order\OrderEntity $order
     *
     * @return string
     */
    public function getParsedLabel(\Shopware\Core\Checkout\Order\OrderEntity $order, string $salesChannelId, string $label)
    {
        $label = $this->getSetting($label, $salesChannelId);

        if ($label === null) {
            return $this->getShopName($salesChannelId);
        }

        $label = preg_replace('/\{order_number\}/', $order->getOrderNumber(), $label);
        $label = preg_replace('/\{shop_name\}/',$this->getShopName($salesChannelId), $label);

        $products = $order->getLineItems();

        if ($products->first() !== null) {
            $label = preg_replace('/\{product_name\}/',$products->first()->getLabel(), $label);
        }
        return mb_substr($label, 0, 244);
    }

    public function getEnvironment($method = '', $salesChannelId = null)
    {
        return $this->getSetting($method . 'Environment', $salesChannelId);
    }

    public function getBuckarooFee($buckarooKey, string $salesChannelId = null)
    {
        if($buckarooFee = $this->getSetting($buckarooKey, $salesChannelId)){
            return round((float)str_replace(',','.',$buckarooFee), 2);
        }
        return 0;
    }


}
