<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

interface InstallerInterface
{
    /**
     * Runs when plugin is installed.
     *
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void;

    /**
     * Run when plugin is uninstalled.
     *
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void;

    /**
     * Run when plugin is activated.
     *
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void;

    /**
     * Run when plugin is deactivated
     *
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void;

    public function update(UpdateContext $updateContext): void;
}
