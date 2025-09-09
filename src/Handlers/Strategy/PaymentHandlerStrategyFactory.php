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
        return class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class);
    }

    /**
     * Create modern strategy with dependencies
     */
    private function createModernStrategy(): PaymentHandlerStrategyInterface
    {
        $modernHandler = new \Buckaroo\Shopware6\Handlers\PaymentHandlerModern(
            $this->asyncPaymentService
        );
        
        return new ModernPaymentHandlerStrategy($modernHandler);
    }

    /**
     * Create legacy strategy with dependencies
     */
    private function createLegacyStrategy(): PaymentHandlerStrategyInterface
    {
        $legacyHandler = new \Buckaroo\Shopware6\Handlers\PaymentHandlerLegacy(
            $this->asyncPaymentService
        );
        
        return new LegacyPaymentHandlerStrategy($legacyHandler);
    }
}
