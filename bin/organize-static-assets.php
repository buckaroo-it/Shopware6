#!/usr/bin/env php
<?php

/**
 * One-time script to organize existing static assets
 * 
 * This script moves static assets from public/ directories to the source location
 * in src/Resources/app/administration/static/
 * 
 * Usage: php bin/organize-static-assets.php
 */

$pluginRoot = dirname(__DIR__);
$sourceDir = $pluginRoot . '/src/Resources/app/administration/static';
$publicAdminStaticDir = $pluginRoot . '/src/Resources/public/administration/static';

echo "========================================\n";
echo "Organizing Static Assets\n";
echo "========================================\n\n";

// Create source directory if it doesn't exist
if (!is_dir($sourceDir)) {
    mkdir($sourceDir, 0755, true);
    echo "Created source directory: $sourceDir\n";
}

// Check if we have files in the public directory
if (is_dir($publicAdminStaticDir)) {
    $files = array_diff(scandir($publicAdminStaticDir), ['.', '..']);
    
    if (count($files) > 0) {
        echo "Found " . count($files) . " files in public directory.\n";
        echo "Moving files to source directory...\n\n";
        
        foreach ($files as $file) {
            $srcPath = $publicAdminStaticDir . DIRECTORY_SEPARATOR . $file;
            $dstPath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            
            // Only move if it's a file (not a directory)
            if (is_file($srcPath)) {
                // Don't overwrite if file already exists in source
                if (!file_exists($dstPath)) {
                    if (copy($srcPath, $dstPath)) {
                        echo "✓ Moved: $file\n";
                    } else {
                        echo "✗ Failed to move: $file\n";
                    }
                } else {
                    echo "- Skipped (already exists): $file\n";
                }
            }
        }
        
        echo "\n========================================\n";
        echo "Organization completed!\n";
        echo "========================================\n\n";
        echo "Next steps:\n";
        echo "1. Review the files in: $sourceDir\n";
        echo "2. Run: composer run post-build\n";
        echo "3. Commit the source files and ignore the public/ directories\n";
    } else {
        echo "No files found in public directory.\n";
    }
} else {
    echo "Public directory doesn't exist. Nothing to organize.\n";
    echo "Add your static assets to: $sourceDir\n";
}

