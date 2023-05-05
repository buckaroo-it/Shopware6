<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Helpers\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlService
{
    protected SettingsService $settingsService;

    protected UrlGeneratorInterface $router;

    public function __construct(
        SettingsService $settingsService,
        UrlGeneratorInterface $router
    ) {
        $this->settingsService = $settingsService;
        $this->router = $router;
    }
    /**
     * Get the base url
     * When the environment is set live, but the payment is set as test, the test url will be used
     *
     * @return string Base-url
     */
    public function getBaseUrl($method = '', string $salesChannelId = null): string
    {
        return $this->settingsService->getEnvironment($method, $salesChannelId) == 'live' ? UrlHelper::LIVE : UrlHelper::TEST;
    }

    /**
     * @return string Full transaction url
     */
    public function getTransactionUrl($method = '', string $salesChannelId = null): string
    {
        return rtrim($this->getBaseUrl($method, $salesChannelId), '/') . '/' . ltrim('json/Transaction', '/');
    }

    /**
     * @return string Full transaction url
     */
    public function getApiTestUrl($method = '', string $salesChannelId = null): string
    {
        return rtrim($this->getBaseUrl($method, $salesChannelId), '/') . '/' . ltrim('json/DataRequest/Specifications', '/');
    }

    /**
     * @return string Full transaction url
     */
    public function getDataRequestUrl($method = '', string $salesChannelId = null): string
    {
        return rtrim($this->getBaseUrl($method, $salesChannelId), '/') . '/' . ltrim('json/DataRequest', '/');
    }

    public function getReturnUrl($route): string
    {
        return $this->getSaleBaseUrl() . $this->router->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    public function getSaleBaseUrl()
    {
        $checkoutConfirmUrl = $this->router->generate(
            'frontend.checkout.confirm.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        return str_replace('/checkout/confirm', '', $checkoutConfirmUrl);
    }

    public function forwardToRoute($path, $parameters = [])
    {
        return $this->router->generate($path, $parameters);
    }

    public function getRestoreUrl()
    {
        return $this->router->generate(
            'frontend.action.buckaroo.redirect',
            [],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }
}
