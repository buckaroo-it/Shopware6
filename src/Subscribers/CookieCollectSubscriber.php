<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Struct\CookieEntry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber to register Buckaroo-specific cookies in Shopware 6.7+
 * Replaces the deprecated CookieProviderInterface implementation
 */
class CookieCollectSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CookieGroupCollectEvent::class => 'onCookieGroupCollect',
        ];
    }

    /**
     * Add Buckaroo cookies to the required cookie group
     *
     * @param CookieGroupCollectEvent $event
     * @return void
     */
    public function onCookieGroupCollect(CookieGroupCollectEvent $event): void
    {
        $cookieGroups = $event->cookieGroupCollection;

        // Find the required cookie group
        $requiredGroup = null;
        foreach ($cookieGroups as $group) {
            if ($group->isRequired() && $group->getName() === 'cookie.groupRequired') {
                $requiredGroup = $group;
                break;
            }
        }

        // If we found the required group, add our cookies
        if ($requiredGroup !== null) {
            $existingEntries = $requiredGroup->getEntries();

            // Add Buckaroo-specific cookies
            $buckarooCookies = ['__cfduid', 'ARRAffinity', 'ARRAffinitySameSite'];
            
            foreach ($buckarooCookies as $cookieName) {
                $cookieEntry = new CookieEntry(
                    $cookieName,
                    'Buckaroo Payments - ' . $cookieName,
                    null // Optional: Add snippet description key if needed
                );
                $existingEntries[] = $cookieEntry;
            }

            $requiredGroup->setEntries($existingEntries);
        }
    }
}

