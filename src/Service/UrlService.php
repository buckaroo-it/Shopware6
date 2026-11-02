<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Helpers\UrlHelper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class UrlService
{
    /**
     * Path of the sales-channel-independent push endpoint (see PushController::pushBuckarooApi()).
     * The sales channel id of the order is appended as the last path segment.
     */
    public const API_PUSH_PATH = '/api/buckaroo/push';

    protected SettingsService $settingsService;

    protected UrlGeneratorInterface $router;

    private TokenFactoryInterfaceV2 $tokenFactory;

    private EntityRepository $salesChannelRepository;

    private string $appUrl;

    public function __construct(
        SettingsService $settingsService,
        UrlGeneratorInterface $router,
        TokenFactoryInterfaceV2 $tokenFactory,
        EntityRepository $salesChannelRepository,
        string $appUrl = ''
    ) {
        $this->settingsService = $settingsService;
        $this->router = $router;
        $this->tokenFactory = $tokenFactory;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->appUrl = $appUrl;
    }

    /**
     * Returns the push URL for Buckaroo callbacks, using the order's sales channel domain
     * so the URL includes the correct language path (e.g. /en) when using shop.com/en-style domains.
     *
     * When $baseUrl is provided (e.g. the checkout return URL), the domain whose configured URL is
     * the longest prefix of $baseUrl is selected. This handles both different-host storefronts
     * (www.shop.com vs kiosk.shop.com) and same-host language-path storefronts (shop.com/en vs
     * shop.com/de) without falling back on non-deterministic languageId or first() ordering.
     *
     * The sales channel lookup runs on the caller's $context, so the sales channel, language and
     * permission scope of the request are preserved in multi-sales-channel installations.
     *
     * Orders of a non-storefront sales channel (API/headless) get the sales-channel-independent
     * API push URL instead: Shopware only maps storefront-type domains to a request, so the
     * storefront push route can never be reached for such an order, and the domains of a
     * headless sales channel usually point at an external frontend that does not serve the
     * push route at all.
     */
    public function getPushUrlForOrder(OrderEntity $order, Context $context, ?string $baseUrl = null): string
    {
        $criteria = new Criteria([$order->getSalesChannelId()]);
        $criteria->addAssociation('domains');

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->getEntities()->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return $this->getReturnUrl('buckaroo.payment.push');
        }

        if ($salesChannel->getTypeId() !== Defaults::SALES_CHANNEL_TYPE_STOREFRONT) {
            return $this->getApiPushUrl($order->getSalesChannelId());
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null || $domains->count() === 0) {
            return $this->getReturnUrl('buckaroo.payment.push');
        }

        if ($baseUrl !== null) {
            $matchedDomain = $this->findDomainForUrl($domains, $baseUrl);
            if ($matchedDomain !== null) {
                return rtrim($matchedDomain->getUrl(), '/') . '/buckaroo/push';
            }
        }

        $orderLanguageId = $order->getLanguageId();
        foreach ($domains as $domain) {
            if ($domain->getLanguageId() === $orderLanguageId) {
                return rtrim($domain->getUrl(), '/') . '/buckaroo/push';
            }
        }

        $firstDomain = $domains->first();
        if ($firstDomain === null) {
            return $this->getReturnUrl('buckaroo.payment.push');
        }

        return rtrim($firstDomain->getUrl(), '/') . '/buckaroo/push';
    }

    /**
     * Returns the push URL of the API push endpoint for the given sales channel.
     *
     * The URL is built on APP_URL, the backend URL Shopware itself uses for server-to-server
     * callbacks (app registration, webhooks): it is correct in every context the push URL is
     * generated in - storefront or Store API request, admin request, message queue worker or
     * scheduled task - whereas the router only knows the host of the current HTTP request and
     * falls back to "localhost" when there is none. APP_URL already contains any sub-directory
     * Shopware is installed in, so the route path is appended as-is.
     *
     * When APP_URL is not set, the router is used as a last resort.
     */
    public function getApiPushUrl(string $salesChannelId): string
    {
        $appBaseUrl = $this->getAppBaseUrl();
        if ($appBaseUrl !== null) {
            return $appBaseUrl . self::API_PUSH_PATH . '/' . $salesChannelId;
        }

        return $this->router->generate(
            'api.buckaroo.push',
            ['salesChannelId' => $salesChannelId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * APP_URL without a trailing slash, or null when it is not set or is not an absolute
     * http(s) URL (so a malformed value can never end up in a push URL).
     */
    private function getAppBaseUrl(): ?string
    {
        $appUrl = rtrim(trim($this->appUrl), '/');
        if ($appUrl === '' || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($appUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return $appUrl;
    }

    /**
     * Returns the cancel URL for Buckaroo redirects, using the order's sales channel domain.
     * When using multiple storefronts with different domains, the cancel redirect must land on the
     * same domain where the customer started checkout so the sw-context-token and session work.
     *
     * When $baseUrl is provided (e.g. the checkout return URL), the domain whose configured URL is
     * the longest prefix of $baseUrl is selected. This handles both different-host storefronts
     * (www.shop.com vs kiosk.shop.com) and same-host language-path storefronts (shop.com/en vs
     * shop.com/de) without falling back on non-deterministic languageId or first() ordering.
     *
     * The sales channel lookup runs on the caller's $context, so the sales channel, language and
     * permission scope of the request are preserved in multi-sales-channel installations.
     */
    public function getCancelUrlForOrder(OrderEntity $order, Context $context, ?string $baseUrl = null): string
    {
        $criteria = new Criteria([$order->getSalesChannelId()]);
        $criteria->addAssociation('domains');

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->getEntities()->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return $this->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null || $domains->count() === 0) {
            return $this->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
        }

        if ($baseUrl !== null) {
            $matchedDomain = $this->findDomainForUrl($domains, $baseUrl);
            if ($matchedDomain !== null) {
                return rtrim($matchedDomain->getUrl(), '/') . '/buckaroo/cancel';
            }
        }

        $orderLanguageId = $order->getLanguageId();
        foreach ($domains as $domain) {
            if ($domain->getLanguageId() === $orderLanguageId) {
                return rtrim($domain->getUrl(), '/') . '/buckaroo/cancel';
            }
        }

        $firstDomain = $domains->first();
        if ($firstDomain === null) {
            return $this->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
        }

        return rtrim($firstDomain->getUrl(), '/') . '/buckaroo/cancel';
    }

    /**
     * Finds the sales channel domain whose configured URL is the longest prefix of $url.
     *
     * Matching is done at path-segment boundaries (e.g. domain "shop.com/en" matches
     * "shop.com/en/checkout/..." but not "shop.com/en2/..."). The longest match wins so that a
     * more-specific domain (shop.com/en/b2b) beats a less-specific one (shop.com/en).
     *
     * Returns null when no domain shares the same scheme + host as $url.
     */
    private function findDomainForUrl(
        SalesChannelDomainCollection $domains,
        string $url
    ): ?SalesChannelDomainEntity {
        $urlOrigin = $this->extractOrigin($url);
        if ($urlOrigin === null) {
            return null;
        }

        $bestMatch = null;
        $bestMatchLength = -1;

        foreach ($domains as $domain) {
            $domainUrl = rtrim($domain->getUrl(), '/');

            if ($this->extractOrigin($domainUrl) !== $urlOrigin) {
                continue;
            }

            // Accept when $url equals the domain URL exactly, or when $url starts with domainUrl
            // followed by '/' (ensures we match at a path-segment boundary, not mid-segment).
            if ($url !== $domainUrl && !str_starts_with($url, $domainUrl . '/')) {
                continue;
            }

            $length = strlen($domainUrl);
            if ($length > $bestMatchLength) {
                $bestMatch = $domain;
                $bestMatchLength = $length;
            }
        }

        return $bestMatch;
    }

    /**
     * Extracts scheme + host (+ optional port) from a URL.
     * Returns null if the URL cannot be parsed or is missing scheme/host.
     */
    private function extractOrigin(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    public function getReturnUrl(string $route): string
    {
        return $this->router->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * Generate absolute URL for a route with parameters.
     * Use this instead of getSaleBaseUrl() + forwardToRoute() to avoid double path segments
     * (e.g. /en/en/) when using language prefixes like localhost/en.
     *
     * @param string $route
     * @param array<mixed> $parameters
     * @return string
     */
    public function generateAbsoluteUrl(string $route, array $parameters = []): string
    {
        return $this->router->generate(
            $route,
            $parameters,
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function getSaleBaseUrl(): string
    {
        $checkoutConfirmUrl = $this->router->generate(
            'frontend.checkout.confirm.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        return str_replace('/checkout/confirm', '', $checkoutConfirmUrl);
    }
    /**
     * @param string $path
     * @param array<mixed> $parameters
     *
     * @return string
     */
    public function forwardToRoute(string $path, array $parameters = []): string
    {
        return $this->router->generate($path, $parameters);
    }

    public function getRestoreUrl(): string
    {
        return $this->router->generate(
            'frontend.action.buckaroo.redirect',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }


    /**
     * Generate return url with access token
     *
     * @param OrderTransactionEntity $transaction
     * @param integer $paymentFinalizeTransactionTime minutes
     *
     * @return string
     */
    public function generateReturnUrl(OrderTransactionEntity $transaction, int $paymentFinalizeTransactionTime): string
    {
        $finishUrl = $this->router->generate(
            'frontend.checkout.finish.page',
            ['orderId' => $transaction->getOrderId()]
        );

        $errorUrl = $this->router->generate(
            'frontend.account.edit-order.page',
            ['orderId' => $transaction->getOrderId()]
        );

        $tokenStruct = new TokenStruct(
            null,
            null,
            $transaction->getPaymentMethodId(),
            $transaction->getId(),
            $finishUrl,
            $paymentFinalizeTransactionTime * 60,
            $errorUrl
        );

        $token = $this->tokenFactory->generateToken($tokenStruct);

        return $this->assembleReturnUrl($token);
    }

    /**
     * @param string $token
     *
     * @return string
     */
    private function assembleReturnUrl(string $token): string
    {
        $parameter = ['_sw_payment_token' => $token];

        return $this->router->generate('payment.finalize.transaction', $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
