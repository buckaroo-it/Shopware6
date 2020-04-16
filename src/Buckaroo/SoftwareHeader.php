<?php

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Helpers\PluginInfo;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware;

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
		$this->pluginInfo = $pluginInfo;
        $this->shopwareVersion = $shopwareVersion;
        $this->pluginService = $pluginService;
	}

    public function getHeader()
    {
        return "Software: " . json_encode([
            "PlatformName" => "Shopware",
            "PlatformVersion" => $this->shopwareVersion,
            "ModuleSupplier" => "X",
            "ModuleName" => "X",
            "ModuleVersion" => "1.0"
        ]);
    }
}
