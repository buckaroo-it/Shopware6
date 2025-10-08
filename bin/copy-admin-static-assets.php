#!/usr/bin/env php
<?php

/**
 * Post-build script to ensure administration static assets are available in both locations
 * for Shopware 6.6.x (<6.7) and Shopware 6.7+ compatibility
 * 
 * Usage: php bin/copy-admin-static-assets.php
 */

$pluginRoot = dirname(__DIR__);
$sourceDir = $pluginRoot . '/src/Resources/app/administration/static';
$publicAdminStaticDir = $pluginRoot . '/src/Resources/public/administration/static';
$publicStaticDir = $pluginRoot . '/src/Resources/public/static';

/**
 * Copy directory recursively
 */
function copyDirectory($source, $destination) {
    if (!is_dir($source)) {
        echo "Source directory does not exist: $source\n";
        return false;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $files = scandir($source);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcPath = $source . DIRECTORY_SEPARATOR . $file;
        $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
            echo "Copied: $file\n";
        }
    }

    return true;
}

echo "========================================\n";
echo "Copying administration static assets...\n";
echo "========================================\n\n";

// Check if source directory exists
if (!is_dir($sourceDir)) {
    echo "ERROR: Source directory not found: $sourceDir\n";
    echo "Please ensure your static assets are in src/Resources/app/administration/static/\n";
    exit(1);
}

// Copy to both locations for compatibility
echo "Copying to: $publicAdminStaticDir (for Shopware <6.7)\n";
copyDirectory($sourceDir, $publicAdminStaticDir);

echo "\nCopying to: $publicStaticDir (for Shopware 6.7+)\n";
copyDirectory($sourceDir, $publicStaticDir);

echo "\n========================================\n";
echo "Asset copy completed successfully!\n";
echo "========================================\n";

