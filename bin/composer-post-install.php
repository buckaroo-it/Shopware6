#!/usr/bin/env php
<?php

/**
 * Composer post-install/post-update hook
 * 
 * This script runs automatically when the plugin is installed or updated via Composer.
 * It ensures static assets are copied to both locations for Shopware version compatibility.
 */

$scriptPath = __DIR__ . '/copy-admin-static-assets.php';

// Check if we're in the plugin directory
if (!file_exists($scriptPath)) {
    // Script not found - might be running from Shopware root or during dev
    // This is not an error, just skip silently
    exit(0);
}

echo "\n";
echo "================================================================================\n";
echo " BuckarooPayments: Copying administration static assets...\n";
echo "================================================================================\n";
echo "\n";

// Run the asset copy script
$result = 0;
passthru('php ' . escapeshellarg($scriptPath), $result);

if ($result === 0) {
    echo "\n";
    echo "✓ Assets copied successfully for Shopware 6.5.x - 6.8.x compatibility\n";
    echo "\n";
} else {
    echo "\n";
    echo "⚠ Warning: Failed to copy assets automatically.\n";
    echo "  This might be due to file permissions.\n";
    echo "  You can copy assets manually by running:\n";
    echo "  cd custom/plugins/BuckaroPayments && php bin/copy-admin-static-assets.php\n";
    echo "\n";
    // Don't fail the installation
    exit(0);
}

