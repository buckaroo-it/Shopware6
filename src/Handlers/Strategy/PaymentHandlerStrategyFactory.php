<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Strategy;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Handlers\PaymentUrlGenerator;
use Buckaroo\Shopware6\Handlers\PaymentFeeCalculator;
use Buckaroo\Shopware6\Handlers\PaymentPayloadBuilder;
use Buckaroo\Shopware6\Handlers\PaymentResponseHandler;

/**
 * Factory for creating the appropriate payment handler strategy
 */
class PaymentHandlerStrategyFactory
{
    private AsyncPaymentService $asyncPaymentService;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->asyncPaymentService = $asyncPaymentService;
    }

    /**
     * Create the appropriate strategy based on Shopware version
     */
    public function createStrategy(): PaymentHandlerStrategyInterface
    {
        if ($this->isModernStrategyAvailable()) {
            return $this->createModernStrategy();
        }
        
        return $this->createLegacyStrategy();
    }

    /**
     * Get the strategy name that would be used
     */
    public function getStrategyName(): string
    {
        return $this->isModernStrategyAvailable() ? 'modern' : 'legacy';
    }

    /**
     * Check if modern strategy is available
     */
    public function isModernStrategyAvailable(): bool
    {
        // Check if Shopware Core version is 6.7.0 or above
        if ($this->isShopwareVersionAtLeast('6.7.0')) {
            return true;
        }
        
        // Fallback to class existence checks for older versions
        // First check if the Shopware abstract class exists
        if (!class_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\AbstractPaymentHandler')) {
            return false;
        }
        
        // Then check if our modern handler class exists and can be loaded
        $modernHandlerClass = 'Buckaroo\\Shopware6\\Handlers\\PaymentHandlerModern';
        if (!class_exists($modernHandlerClass)) {
            return false;
        }
        
        // Additional safety check for the modern strategy classes
        return class_exists('Buckaroo\\Shopware6\\Handlers\\Strategy\\ModernPaymentHandlerStrategy');
    }

    /**
     * Create modern strategy with dependencies
     */
    private function createModernStrategy(): PaymentHandlerStrategyInterface
    {
        $modernHandlerClass = 'Buckaroo\\Shopware6\\Handlers\\PaymentHandlerModern';
        
        if (!class_exists($modernHandlerClass)) {
            throw new \RuntimeException('Modern payment handler class not available');
        }
        
        // Create the required dependencies
        $urlGenerator = new PaymentUrlGenerator($this->asyncPaymentService);
        $feeCalculator = new PaymentFeeCalculator($this->asyncPaymentService);
        $payloadBuilder = new PaymentPayloadBuilder($this->asyncPaymentService, $urlGenerator, $feeCalculator);
        $responseHandler = new PaymentResponseHandler($this->asyncPaymentService, $feeCalculator);
        
        $modernHandler = new $modernHandlerClass(
            $this->asyncPaymentService,
            $urlGenerator,
            $feeCalculator,
            $payloadBuilder,
            $responseHandler
        );
        
        return new ModernPaymentHandlerStrategy($modernHandler);
    }

    /**
     * Create legacy strategy with dependencies
     */
    private function createLegacyStrategy(): PaymentHandlerStrategyInterface
    {
        $legacyHandlerClass = 'Buckaroo\\Shopware6\\Handlers\\PaymentHandlerLegacy';
        
        if (!class_exists($legacyHandlerClass)) {
            throw new \RuntimeException('Legacy payment handler class not available');
        }
        
        $legacyHandler = new $legacyHandlerClass(
            $this->asyncPaymentService
        );
        
        return new LegacyPaymentHandlerStrategy($legacyHandler);
    }

    /**
     * Check if Shopware version is at least the specified version
     */
    private function isShopwareVersionAtLeast(string $minVersion): bool
    {
        // Try to get the Shopware version from the Kernel class
        if (class_exists('Shopware\\Core\\Kernel')) {
            try {
                $kernelClass = 'Shopware\\Core\\Kernel';
                $reflection = new \ReflectionClass($kernelClass);
                if ($reflection->hasConstant('SHOPWARE_FALLBACK_VERSION')) {
                    $shopwareVersion = $reflection->getConstant('SHOPWARE_FALLBACK_VERSION');

                    return version_compare($shopwareVersion, $minVersion, '>=');
                }
            } catch (\Throwable $e) {
                // If we can't determine the version, fall back to class existence checks
            }
        }
        
        // Alternative method: check if InstalledVersions class exists (Composer 2.0+)
        if (class_exists('Composer\\InstalledVersions')) {
            try {
                $installedVersionsClass = 'Composer\\InstalledVersions';
                $version = call_user_func([$installedVersionsClass, 'getVersion'], 'shopware/core');
                if ($version !== null) {
                    // Remove any version prefix like 'v' and extract just the version number
                    $version = ltrim($version, 'v');
                    // Handle dev versions by taking only the numeric part
                    if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
                        $version = $matches[1];
                    }
                    return version_compare($version, $minVersion, '>=');
                }
            } catch (\Throwable $e) {
                // If we can't determine the version, fall back to class existence checks
            }
        }
        
        // Fallback: return false to use legacy checks
        return false;
    }
}
