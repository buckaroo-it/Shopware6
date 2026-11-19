<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Helpers;

use Buckaroo\Shopware6\Helpers\StaleClassMapCleaner;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

class StaleClassMapCleanerTest extends TestCase
{
    /**
     * A plugin class whose file is gone is removed from the class map, so loading it finds
     * nothing instead of including a missing file. Existing files and other vendors' classes stay.
     */
    public function testRemovesOnlyPluginClassesWhoseFileIsGone(): void
    {
        $existing = __FILE__;
        $missing = sys_get_temp_dir() . '/buckaroo-removed-' . uniqid() . '/RemovedPaymentHandler.php';

        $loader = new ClassLoader();
        $loader->addClassMap([
            'Buckaroo\\Shopware6\\Handlers\\RemovedPaymentHandler' => $missing,
            'Buckaroo\\Shopware6\\Handlers\\KeptPaymentHandler' => $existing,
            'Other\\Vendor\\MissingClass' => $missing,
        ]);

        $removed = StaleClassMapCleaner::clean([$loader]);

        $this->assertSame(['Buckaroo\\Shopware6\\Handlers\\RemovedPaymentHandler'], $removed);
        // Composer (and Symfony's DebugClassLoader) only include a file for a non-empty path
        $this->assertEmpty($loader->findFile('Buckaroo\\Shopware6\\Handlers\\RemovedPaymentHandler'));
        $this->assertNotTrue($loader->loadClass('Buckaroo\\Shopware6\\Handlers\\RemovedPaymentHandler'));
        $this->assertSame($existing, $loader->findFile('Buckaroo\\Shopware6\\Handlers\\KeptPaymentHandler'));
        $this->assertSame($missing, $loader->getClassMap()['Other\\Vendor\\MissingClass']);

        $this->assertSame([], StaleClassMapCleaner::clean([$loader]), 'A second run finds nothing new');
    }

    /**
     * After Composer ran in the same process (plugin update), the loader that resolves classes is
     * only reachable through the autoload stack, wrapped by Symfony's DebugClassLoader in debug mode
     */
    public function testFindsLoadersWrappedOnTheAutoloadStack(): void
    {
        $loader = new ClassLoader();
        $wrapper = new class ([$loader, 'loadClass']) {
            /** @param callable $classLoader */
            public function __construct(private $classLoader)
            {
            }

            /** @return callable */
            public function getClassLoader()
            {
                return $this->classLoader;
            }

            public function loadClass(string $class): void
            {
            }
        };
        $autoloader = [$wrapper, 'loadClass'];

        spl_autoload_register($autoloader);
        try {
            $this->assertContains($loader, StaleClassMapCleaner::activeLoaders());
        } finally {
            spl_autoload_unregister($autoloader);
        }
    }
}
