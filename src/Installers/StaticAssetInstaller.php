<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Installers;

use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Installer for static assets to ensure cross-version compatibility
 * 
 * This installer copies static assets from the source directory to both
 * public locations to support Shopware <6.7 and >=6.7
 */
class StaticAssetInstaller implements InstallerInterface
{
    private string $pluginPath;

    public function __construct(string $pluginPath)
    {
        $this->pluginPath = $pluginPath;
    }

    public function install(InstallContext $context): void
    {
        $this->copyStaticAssets();
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->copyStaticAssets();
    }

    public function activate(ActivateContext $context): void
    {
        $this->copyStaticAssets();
    }

    public function deactivate(DeactivateContext $context): void
    {
        // Keep assets on deactivation
    }

    public function uninstall(UninstallContext $context): void
    {
        // Keep assets on uninstall - they may be needed for historical data
    }

    /**
     * Copy static assets from source to both public locations
     * for Shopware version compatibility
     */
    private function copyStaticAssets(): void
    {
        $sourceDir = $this->pluginPath . '/src/Resources/app/administration/static';
        
        // Target directories for different Shopware versions
        $targets = [
            $this->pluginPath . '/src/Resources/public/administration/static', // < 6.7
            $this->pluginPath . '/src/Resources/public/static',               // >= 6.7
        ];

        if (!is_dir($sourceDir)) {
            // No static assets to copy - this is not an error, plugin might not have static assets yet
            return;
        }

        foreach ($targets as $targetDir) {
            try {
                $this->copyDirectory($sourceDir, $targetDir);
            } catch (\Exception $e) {
                // Log the error but don't fail the installation
                // The assets can still be copied manually via composer script
                error_log(sprintf(
                    'BuckarooPayments: Failed to copy static assets to %s: %s',
                    $targetDir,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Recursively copy directory contents
     * 
     * @throws \RuntimeException if directory creation or file copy fails
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException("Source directory does not exist: {$source}");
        }

        // Check if source is readable
        if (!is_readable($source)) {
            throw new \RuntimeException("Source directory is not readable: {$source}");
        }

        if (!is_dir($destination)) {
            // Try to create the directory
            if (!@mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new \RuntimeException(
                    "Failed to create destination directory: {$destination}. " .
                    "Please check file permissions or run: composer run post-build"
                );
            }
        }

        // Check if destination is writable
        if (!is_writable($destination)) {
            throw new \RuntimeException(
                "Destination directory is not writable: {$destination}. " .
                "Please check file permissions or run manually: php bin/copy-admin-static-assets.php"
            );
        }

        $files = scandir($source);
        if ($files === false) {
            throw new \RuntimeException("Failed to read source directory: {$source}");
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    // If copy fails, try to get more information
                    $error = error_get_last();
                    throw new \RuntimeException(
                        "Failed to copy file: {$srcPath} to {$dstPath}. " .
                        "Error: " . ($error['message'] ?? 'Unknown error') . ". " .
                        "Run manually: composer run post-build"
                    );
                }
                
                // Try to set proper permissions on the copied file
                @chmod($dstPath, 0644);
            }
        }
    }
}

