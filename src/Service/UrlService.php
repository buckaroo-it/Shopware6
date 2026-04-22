<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Helpers\UrlHelper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class UrlService
{
    protected SettingsService $settingsService;

    protected UrlGeneratorInterface $router;

    private TokenFactoryInterfaceV2 $tokenFactory;

    private EntityRepository $salesChannelRepository;

    public function __construct(
        SettingsService $settingsService,
        UrlGeneratorInterface $router,
        TokenFactoryInterfaceV2 $tokenFactory,
        EntityRepository $salesChannelRepository
    ) {
        $this->settingsService = $settingsService;
        $this->router = $router;
        $this->tokenFactory = $tokenFactory;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    /**
     * Returns the push URL for Buckaroo callbacks, using the order's sales channel domain
     * so the URL includes the correct language path (e.g. /en) when using shop.com/en-style domains.
     *
     * When $baseUrl is provided (e.g. the checkout return URL), the domain whose configured URL is
     * the longest prefix of $baseUrl is selected. This handles both different-host storefronts
     * (www.shop.com vs kiosk.shop.com) and same-host language-path storefronts (shop.com/en vs
     * shop.com/de) without falling back on non-deterministic languageId or first() ordering.
     */
    public function getPushUrlForOrder(OrderEntity $order, ?string $baseUrl = null): string
    {
        $criteria = new Criteria([$order->getSalesChannelId()]);
        $criteria->addAssociation('domains');

        $salesChannel = $this->salesChannelRepository->search($criteria, Context::createDefaultContext())->first();
        if ($salesChannel === null) {
            return $this->getReturnUrl('buckaroo.payment.push');
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
            if ($orderLanguageId !== null && $domain->getLanguageId() === $orderLanguageId) {
                return rtrim($domain->getUrl(), '/') . '/buckaroo/push';
            }
        }

        $firstDomain = $domains->first();
        return $firstDomain !== null ? rtrim($firstDomain->getUrl(), '/') . '/buckaroo/push' : $this->getReturnUrl('buckaroo.payment.push');
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
     */
    public function getCancelUrlForOrder(OrderEntity $order, ?string $baseUrl = null): string
    {
        $criteria = new Criteria([$order->getSalesChannelId()]);
        $criteria->addAssociation('domains');

        $salesChannel = $this->salesChannelRepository->search($criteria, Context::createDefaultContext())->first();
        if ($salesChannel === null) {
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
            if ($orderLanguageId !== null && $domain->getLanguageId() === $orderLanguageId) {
                return rtrim($domain->getUrl(), '/') . '/buckaroo/cancel';
            }
        }

        $firstDomain = $domains->first();
        return $firstDomain !== null ? rtrim($firstDomain->getUrl(), '/') . '/buckaroo/cancel' : $this->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
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
    private function findDomainForUrl(iterable $domains, string $url): ?object
    {
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
     * Use this instead of getSaleBaseUrl() + forwardToRoute() to avoid double path segments (e.g. /en/en/) when using language prefixes like localhost/en.
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
