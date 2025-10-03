<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Factory to provide the correct notification service based on Shopware version
 */
class NotificationServiceFactory
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Get the appropriate notification service for the current Shopware version
     */
    public function getNotificationService(): object
    {
        // Try Core Framework NotificationService first (Shopware 6.7+)
        if ($this->container->has('Shopware\Core\Framework\Notification\NotificationService')) {
            return $this->container->get('Shopware\Core\Framework\Notification\NotificationService');
        }
        
        // Fallback to Administration NotificationService (Shopware 6.5-6.6)
        if ($this->container->has('Shopware\Administration\Notification\NotificationService')) {
            return $this->container->get('Shopware\Administration\Notification\NotificationService');
        }
        
        throw new \RuntimeException('No compatible notification service found');
    }
}
