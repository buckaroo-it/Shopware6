<?php

declare(strict_types=1);

/**
 * Full Shopware Integration Bootstrap
 * 
 * This bootstrap sets up a complete Shopware test environment with database,
 * kernel, and plugin registration - similar to Mollie's approach.
 * 
 * Use this when you need:
 * - Real database access
 * - Full Shopware kernel
 * - Service container with all plugins
 * - DAL repositories
 * 
 * To use this bootstrap instead of the default:
 * 1. Update phpunit.xml: bootstrap="tests/bootstrap-integration.php"
 * 2. Ensure you have a Shopware installation with DATABASE_URL configured
 * 3. Run tests from within Shopware project root
 */

use Shopware\Core\TestBootstrapper;
use Symfony\Component\Dotenv\Dotenv;

// Set test environment
$_ENV['APP_ENV'] = 'test';
$_ENV['KERNEL_CLASS'] = Shopware\Core\Kernel::class;

// Generate a test secret if not set
if (!isset($_ENV['APP_SECRET'])) {
    $_ENV['APP_SECRET'] = bin2hex(random_bytes(32));
}

// Determine project directory
// When running in Shopware installation: custom/plugins/BuckarooPayments/tests
// Project root is 4 levels up: ../../../../
$projectDir = realpath(__DIR__ . '/../../../../');

if (!$projectDir || !file_exists($projectDir . '/composer.json')) {
    echo "❌ ERROR: Could not find Shopware project root\n";
    echo "This bootstrap requires running within a Shopware installation.\n";
    echo "Expected structure: shopware-root/custom/plugins/BuckarooPayments/\n";
    echo "\nFor standalone testing, use the default bootstrap: tests/bootstrap.php\n";
    exit(1);
}

// Load environment variables
$envFilePath = $projectDir . '/.env';

if (is_file($envFilePath) || is_file($envFilePath . '.dist') || is_file($envFilePath . '.local.php')) {
    (new Dotenv())->usePutenv()->bootEnv($envFilePath);
}

// Get database URL from environment
$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    echo "⚠️  WARNING: DATABASE_URL not set in environment\n";
    echo "Some integration tests may fail or be skipped.\n\n";
}

// Bootstrap Shopware test environment
try {
    $testBootstrapper = new TestBootstrapper();
    $testBootstrapper->setProjectDir($projectDir);
    
    if ($databaseUrl) {
        $testBootstrapper->setDatabaseUrl($databaseUrl);
    }
    
    // Register BuckarooPayments plugin
    $testBootstrapper->addActivePlugins('BuckarooPayments');
    
    // Bootstrap the test environment
    $testBootstrapper->bootstrap();
    
    echo "✓ Shopware test environment bootstrapped\n";
    echo "✓ Project directory: {$projectDir}\n";
    echo "✓ BuckarooPayments plugin registered\n";
    
} catch (\Throwable $e) {
    echo "❌ ERROR: Failed to bootstrap Shopware test environment\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nFalling back to simple bootstrap...\n\n";
    
    // Fallback to simple autoload
    require_once __DIR__ . '/bootstrap.php';
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone
date_default_timezone_set('UTC');
