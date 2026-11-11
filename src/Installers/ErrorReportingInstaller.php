<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportingConsent;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Puts the error reporting consent in the "not asked" state on install and on
 * update, unless the merchant already answered, so the administration asks.
 */
class ErrorReportingInstaller implements InstallerInterface
{
    private const CONFIG_KEY = 'BuckarooPayments.config.' . ErrorReportingConsent::CONFIG_KEY;

    private SystemConfigService $systemConfigService;

    public function __construct(?ContainerInterface $container = null)
    {
        if ($container === null) {
            throw new \Exception("Container is null", 1);
        }

        $systemConfigService = $container->get(SystemConfigService::class);
        if (!$systemConfigService instanceof SystemConfigService) {
            throw new \Exception("SystemConfigService not available", 1);
        }
        $this->systemConfigService = $systemConfigService;
    }

    public function install(InstallContext $context): void
    {
        $this->markNotAskedUnlessAnswered();
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->markNotAskedUnlessAnswered();
    }

    public function uninstall(UninstallContext $context): void
    {
        // The plugin configuration is removed by Shopware together with the plugin.
    }

    public function activate(ActivateContext $context): void
    {
    }

    public function deactivate(DeactivateContext $context): void
    {
    }

    private function markNotAskedUnlessAnswered(): void
    {
        $current = $this->systemConfigService->get(self::CONFIG_KEY);
        if (in_array($current, [ErrorReportingConsent::YES, ErrorReportingConsent::NO], true)) {
            return;
        }

        $this->systemConfigService->set(self::CONFIG_KEY, ErrorReportingConsent::NOT_ASKED);
    }
}
