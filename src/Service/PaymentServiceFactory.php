<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Factory to provide the correct payment service based on Shopware version
 */
class PaymentServiceFactory
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Get the appropriate payment service for the current Shopware version
     */
    public function getPaymentService(): object
    {
        // Try PaymentProcessor first (Shopware 6.7+)
        if ($this->container->has('Shopware\Core\Checkout\Payment\PaymentProcessor')) {
            return $this->container->get('Shopware\Core\Checkout\Payment\PaymentProcessor');
        }
        
        // Fallback to PaymentService (Shopware 6.5-6.6)
        if ($this->container->has('Shopware\Core\Checkout\Payment\PaymentService')) {
            return $this->container->get('Shopware\Core\Checkout\Payment\PaymentService');
        }
        
        throw new \RuntimeException('No compatible payment service found');
    }
}