<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Helpers\PluginInfo;
use Shopware;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Context;

class SoftwareHeader
{
    /**
     * @var Buckaroo\Shopware6\Helper\PluginInfo
     */
    protected $pluginInfo;
    protected $shopwareVersion;
    protected $pluginService;

    public function __construct(
        PluginInfo $pluginInfo,
        string $shopwareVersion,
        PluginService $pluginService
    ) {
        $this->pluginInfo      = $pluginInfo;
        $this->shopwareVersion = $shopwareVersion;
        $this->pluginService   = $pluginService;
    }

    public function getHeader()
    {
        $context = Context::createDefaultContext();
        return "Software: " . json_encode([
            "PlatformName"    => "Shopware",
            "PlatformVersion" => $this->shopwareVersion,
            "ModuleSupplier"  => "Buckaroo",
            "ModuleName"      => "BuckarooPayment",
            "ModuleVersion"   => $this->pluginService->getPluginByName('BuckarooPayment', $context)->getVersion(),
        ]);
    }
}
