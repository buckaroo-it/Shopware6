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
     */
    public function getPushUrlForOrder(OrderEntity $order): string
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
     */
    public function getCancelUrlForOrder(OrderEntity $order): string
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

        $orderLanguageId = $order->getLanguageId();
        foreach ($domains as $domain) {
            if ($orderLanguageId !== null && $domain->getLanguageId() === $orderLanguageId) {
                return rtrim($domain->getUrl(), '/') . '/buckaroo/cancel';
            }
        }

        $firstDomain = $domains->first();
        return $firstDomain !== null ? rtrim($firstDomain->getUrl(), '/') . '/buckaroo/cancel' : $this->generateAbsoluteUrl('frontend.action.buckaroo.cancel');
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
