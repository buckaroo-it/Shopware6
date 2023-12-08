<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    // ->addCallingPlugin()
    // ->addActivePlugins('BuckarooPayments')
    // ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('Buckaroo\\Shopware6\\Tests\\', __DIR__);