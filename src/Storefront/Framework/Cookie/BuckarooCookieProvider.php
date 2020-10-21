<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Framework\Cookie;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

class BuckarooCookieProvider implements CookieProviderInterface {

    private $originalService;

    function __construct(CookieProviderInterface $service)
    {
        $this->originalService = $service;
    }

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

            foreach (['__cfduid','_gid','_ga','ARRAffinity','ARRAffinitySameSite'] as $key) {
                $cookie['entries'][] = [
                    'snippet_name' => 'Buckaroo Payments - '.$key,
                    'cookie' => $key,
                ];
            }

        }

        return $cookies;
    }

    private function isRequiredCookieGroup(array $cookie): bool
    {
        return (\array_key_exists('isRequired', $cookie) && $cookie['isRequired'] === true)
            && (\array_key_exists('snippet_name', $cookie) && $cookie['snippet_name'] === 'cookie.groupRequired');
    }

}