<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6;

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}


use Buckaroo\Shopware6\Installers\MediaInstaller;
use Buckaroo\Shopware6\Installers\PaymentMethodsInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class BuckarooPayments extends Plugin
{
    /**
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');
    }

    /**
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        (new MediaInstaller($this->container))->install($installContext);
        (new PaymentMethodsInstaller($this->container))->install($installContext);
        parent::install($installContext);
    }

    /**
     * @param ActivateContext $activateContext
     */
    public function activate(ActivateContext $activateContext): void
    {
        (new PaymentMethodsInstaller($this->container))->activate($activateContext);
        parent::activate($activateContext);
    }

    /**
     * @param DeactivateContext $deactivateContext
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        (new PaymentMethodsInstaller($this->container))->deactivate($deactivateContext);
        parent::deactivate($deactivateContext);
    }

    /**
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }
        
        (new MediaInstaller($this->container))->uninstall($uninstallContext);
        (new PaymentMethodsInstaller($this->container))->uninstall($uninstallContext);
        parent::uninstall($uninstallContext);
    }

    /**
     * @param UpdateContext $updateContext
     */
    public function update(UpdateContext $updateContext): void
    {
        (new PaymentMethodsInstaller($this->container))->update($updateContext);
        (new MediaInstaller($this->container))->update($updateContext);
        parent::update($updateContext);
    }

    public function executeComposerCommands(): bool
    {
        return true;
    }
}
