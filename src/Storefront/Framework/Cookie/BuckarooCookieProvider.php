<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Framework\Cookie;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

/**
 * @deprecated since Shopware 6.7 - Use CookieCollectSubscriber instead
 * This class uses the deprecated CookieProviderInterface which will be removed in Shopware 6.8.
 * Cookie registration is now handled via the CookieGroupCollectEvent in CookieCollectSubscriber.
 * 
 * @see \Buckaroo\Shopware6\Subscribers\CookieCollectSubscriber
 */
class BuckarooCookieProvider implements CookieProviderInterface
{
    private CookieProviderInterface $originalService;

    public function __construct(CookieProviderInterface $service)
    {
        $this->originalService = $service;
    }

    /**
     * @return array<mixed>
     */
    public function getCookieGroups(): array
    {
        $cookies = $this->originalService->getCookieGroups();

        foreach ($cookies as &$cookie) {
            if (!\is_array($cookie)) {
                continue;
            }

            if (!$this->isRequiredCookieGroup($cookie)) {
                continue;
            }

            if (!\array_key_exists('entries', $cookie)) {
                continue;
            }

            foreach (['__cfduid','ARRAffinity','ARRAffinitySameSite'] as $key) {
                $cookie['entries'][] = [
                    'snippet_name' => 'Buckaroo Payments - ' . $key,
                    'cookie' => $key,
                ];
            }
        }

        return $cookies;
    }

    /**
     * @param array<mixed> $cookie
     * @return bool
     */
    private function isRequiredCookieGroup(array $cookie): bool
    {
        return (\array_key_exists('isRequired', $cookie) && $cookie['isRequired'] === true)
            && (\array_key_exists('snippet_name', $cookie) && $cookie['snippet_name'] === 'cookie.groupRequired');
    }
}
