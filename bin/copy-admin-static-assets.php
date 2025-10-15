#!/usr/bin/env php
<?php

/**
 * Post-build script to ensure administration static assets are available in both locations
 * for Shopware 6.6.x (<6.7) and Shopware 6.7+ compatibility
 * 
 * This script MOVES files (not copies) to avoid duplication
 * 
 * Usage: php bin/copy-admin-static-assets.php
 */

$pluginRoot = dirname(__DIR__);
$publicAdminStaticDir = $pluginRoot . '/src/Resources/public/administration/static';
$publicStaticDir = $pluginRoot . '/src/Resources/public/static';

// Determine Shopware root directory (go up from plugin directory)
$shopwareRoot = dirname(dirname(dirname($pluginRoot)));

/**
 * Move directory contents recursively
 */
function moveDirectory($source, $destination, &$movedFiles) {
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
            moveDirectory($srcPath, $dstPath, $movedFiles);
        } else {
            // Only move if file doesn't exist in destination or is different
            if (!file_exists($dstPath) || md5_file($srcPath) !== md5_file($dstPath)) {
                copy($srcPath, $dstPath);
                $movedFiles[] = $srcPath;
                echo "Moved: $file\n";
            } else {
                echo "Skipped (already exists): $file\n";
            }
        }
    }

    return true;
}

/**
 * Remove source files after successful move
 */
function cleanupSourceFiles($files, $sourceRoot) {
    foreach ($files as $file) {
        // Safety: only delete inside the original source directory
        if (strpos($file, rtrim($sourceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            echo "Skip delete outside source: $file\n";
            continue;
        }

        if (file_exists($file)) {
            if (@unlink($file)) {
                echo "Deleted source file: $file\n";
            } else {
                echo "Failed to delete: $file\n";
            }
        }
    }
}

/**
 * Remove empty directories recursively
 */
function removeEmptyDirectories($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $files = scandir($dir);
    $files = array_diff($files, ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            removeEmptyDirectories($path);
        }
    }

    // Remove directory if empty
    $files = scandir($dir);
    $files = array_diff($files, ['.', '..']);
    if (empty($files)) {
        rmdir($dir);
    }
}

echo "========================================\n";
echo "Administration static assets migration\n";
echo "========================================\n\n";

// Determine Shopware version (to decide whether to migrate)
$binConsole = $shopwareRoot . '/bin/console';
$shopwareVersion = null;
if (file_exists($binConsole)) {
    $result = 0;
    ob_start();
    passthru("php " . escapeshellarg($binConsole) . " -V 2>&1", $result);
    $output = trim(ob_get_clean());
    if (preg_match('/Shopware\\s+([0-9]+\\.[0-9]+\\.[0-9]+)/i', $output, $m)) {
        $shopwareVersion = $m[1];
    }
}

if ($shopwareVersion !== null && version_compare($shopwareVersion, '6.7.0', '>=')) {
    echo "Detected Shopware version: $shopwareVersion (>= 6.7)\n";
    echo "Migrating files from administration/static -> public/static...\n\n";

    if (!is_dir($publicAdminStaticDir)) {
        echo "Nothing to migrate. Source directory not found: $publicAdminStaticDir\n";
        echo "Skipping.\n";
        exit(0);
    }

    $movedFiles = [];
    echo "Moving to: $publicStaticDir (for Shopware 6.7+)\n";
    moveDirectory($publicAdminStaticDir, $publicStaticDir, $movedFiles);

    // Clean up source files after successful move
    if (!empty($movedFiles)) {
        echo "\nCleaning up source files...\n";
        cleanupSourceFiles($movedFiles, $publicAdminStaticDir);
        removeEmptyDirectories($publicAdminStaticDir);
        echo "Source files removed from administration/static\n";
    }
} else {
    if ($shopwareVersion !== null) {
        echo "Detected Shopware version: $shopwareVersion (< 6.7)\n";
    } else {
        echo "Could not detect Shopware version. Assuming < 6.7 (no migration).\n";
    }
    echo "No changes required; files should remain in administration/static.\n";
}

// Note: Intentionally not touching storefront assets; this script is admin-only

// Clean up source files after successful move
if (!empty($movedFiles)) {
    echo "\nCleaning up source files...\n";
    cleanupSourceFiles($movedFiles, $sourceDir);
    removeEmptyDirectories($sourceDir);
    echo "Source files removed (files now exist only in public directories)\n";
}

echo "\n========================================\n";
echo "Asset migration step completed\n";
echo "========================================\n";

// Run Shopware console commands
echo "\nRunning Shopware asset commands...\n";
echo "========================================\n\n";

if (file_exists($binConsole)) {
    // Run assets:install
    echo "Running: bin/console assets:install\n";
    $result = 0;
    passthru("php " . escapeshellarg($binConsole) . " assets:install 2>&1", $result);
    
    if ($result === 0) {
        echo "✓ Assets installed successfully\n\n";
    } else {
        echo "⚠ Warning: assets:install failed\n\n";
    }
    
    // Run theme:compile
    echo "Running: bin/console theme:compile\n";
    $result = 0;
    passthru("php " . escapeshellarg($binConsole) . " theme:compile 2>&1", $result);
    
    if ($result === 0) {
        echo "✓ Theme compiled successfully\n\n";
    } else {
        echo "⚠ Warning: theme:compile failed\n\n";
    }
} else {
    echo "⚠ Shopware bin/console not found at: $binConsole\n";
    echo "  Please run the following commands manually:\n";
    echo "  - bin/console assets:install\n";
    echo "  - bin/console theme:compile\n\n";
}

echo "========================================\n";
echo "All operations completed!\n";
echo "========================================\n";

