<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Strategy;

use Buckaroo\Shopware6\Service\AsyncPaymentService;

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
        
        $modernHandler = new $modernHandlerClass(
            $this->asyncPaymentService
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
}
