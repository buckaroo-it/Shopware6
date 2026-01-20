<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 * 
 * This bootstrap file handles both scenarios:
 * 1. Running tests within a Shopware installation (local development)
 * 2. Running tests in isolation (CI/CD, standalone plugin testing)
 */

// Try plugin's own vendor directory first (CI/standalone)
$pluginVendorAutoload = __DIR__ . '/../vendor/autoload.php';

// Try Shopware's vendor directory (local development in Shopware installation)
$shopwareVendorAutoload = __DIR__ . '/../../../../vendor/autoload.php';

if (file_exists($pluginVendorAutoload)) {
    // Plugin has its own vendor directory (CI or standalone testing)
    require_once $pluginVendorAutoload;
    echo "✓ Using plugin vendor autoload: {$pluginVendorAutoload}\n";
} elseif (file_exists($shopwareVendorAutoload)) {
    // Running within Shopware installation
    require_once $shopwareVendorAutoload;
    echo "✓ Using Shopware vendor autoload: {$shopwareVendorAutoload}\n";
} else {
    echo "✗ Could not find vendor/autoload.php in either location:\n";
    echo "  - Plugin vendor: {$pluginVendorAutoload}\n";
    echo "  - Shopware vendor: {$shopwareVendorAutoload}\n";
    echo "\nPlease run 'composer install' in the appropriate directory.\n";
    exit(1);
}

// Handle PHP 8.2 compatibility: Shopware's Context class uses PHP 8.3+ syntax
// If Context can't be loaded (parse error), create a polyfill
if (PHP_VERSION_ID < 80300) {
    try {
        // Try to load Context class
        class_exists(\Shopware\Core\Framework\Context::class);
    } catch (\ParseError $e) {
        // Context has PHP 8.3+ syntax, create a minimal polyfill
        if (!class_exists(\Shopware\Core\Framework\Context::class, false)) {
            eval('
            namespace Shopware\Core\Framework {
                class Context {
                    public function getVars(): array { return []; }
                    public function getSource() { return null; }
                    public function getScope(): string { return "test"; }
                    public function getVersionId(): string { return "test"; }
                    public function getRuleIds(): array { return []; }
                }
            }
            ');
            echo "✓ Created Context polyfill for PHP " . PHP_VERSION . "\n";
        }
    }
}

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone to prevent warnings
date_default_timezone_set('UTC');
