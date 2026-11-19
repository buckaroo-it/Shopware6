<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers;

use Composer\Autoload\ClassLoader;

/**
 * Shops with an optimized Composer autoloader keep a class map of the plugin's classes. During a
 * plugin update the running process still holds the class map of the previous version, so a class
 * that the new version removed (e.g. a payment handler of a discontinued payment method) still
 * points to a file that is gone. Shopware 6.5 checks the handler class of every payment method when
 * payment methods are loaded, and including the missing file then aborts the update in debug mode
 * and deactivates the plugin.
 *
 * Removing those entries from the class map in memory makes such classes simply unknown, as they
 * are after the update. Composer rewrites the class map on disk itself.
 */
class StaleClassMapCleaner
{
    public const PLUGIN_NAMESPACE = 'Buckaroo\\Shopware6\\';

    /**
     * @param iterable<ClassLoader>|null $loaders defaults to the active Composer class loaders
     *
     * @return string[] the classes removed from the class map
     */
    public static function clean(?iterable $loaders = null, string $namespace = self::PLUGIN_NAMESPACE): array
    {
        $removed = [];

        foreach ($loaders ?? self::activeLoaders() as $loader) {
            $stale = [];

            foreach ($loader->getClassMap() as $class => $file) {
                if ($file !== '' && str_starts_with($class, $namespace) && !is_file($file)) {
                    // An empty path makes Composer report the class as not found, without a lookup
                    $stale[$class] = '';
                }
            }

            if ($stale !== []) {
                $loader->addClassMap($stale);
                $removed = array_merge($removed, array_keys($stale));
            }
        }

        return $removed;
    }

    /**
     * The Composer class loaders that resolve classes in this process. A plugin update runs Composer
     * in the same process, which registers new loaders in ClassLoader::getRegisteredLoaders() while
     * the autoloader of the running application stays the original one. So the loaders are taken
     * from the autoload stack, including those wrapped by Symfony's DebugClassLoader in debug mode.
     *
     * @return ClassLoader[]
     */
    public static function activeLoaders(): array
    {
        $loaders = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loaders[spl_object_id($loader)] = $loader;
        }

        foreach (spl_autoload_functions() as $autoloader) {
            $loader = \is_array($autoloader) ? $autoloader[0] : $autoloader;

            if (\is_object($loader) && !$loader instanceof ClassLoader && method_exists($loader, 'getClassLoader')) {
                $wrapped = $loader->getClassLoader();
                $loader = \is_array($wrapped) ? $wrapped[0] : $wrapped;
            }

            if ($loader instanceof ClassLoader) {
                $loaders[spl_object_id($loader)] = $loader;
            }
        }

        return array_values($loaders);
    }
}
