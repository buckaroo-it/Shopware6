<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\UrlService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * UrlService must never invent a context of its own: a default context carries neither the
 * sales channel nor the language of the caller, which in a multi-sales-channel shop resolves
 * the push/cancel URL against the wrong storefront domain.
 *
 * These tests pin that the caller's Context reaches the sales channel lookup unchanged and
 * that the domain selection still behaves as before.
 */
class UrlServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d6cb27ee7f2ea0e1b3c4a5d6e7f8';
    private const EN_LANGUAGE_ID   = '0191d6cb27ee7f2ea0e1b3c4a5d6e701';
    private const DE_LANGUAGE_ID   = '0191d6cb27ee7f2ea0e1b3c4a5d6e702';

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var UrlGeneratorInterface&MockObject */
    private UrlGeneratorInterface $router;

    /** @var TokenFactoryInterfaceV2&MockObject */
    private TokenFactoryInterfaceV2 $tokenFactory;

    /** @var EntityRepository&MockObject */
    private EntityRepository $salesChannelRepository;

    private UrlService $urlService;

    /** @var array<int, Context> */
    private array $usedContexts = [];

    protected function setUp(): void
    {
        $this->settingsService        = $this->createMock(SettingsService::class);
        $this->router                 = $this->createMock(UrlGeneratorInterface::class);
        $this->tokenFactory           = $this->createMock(TokenFactoryInterfaceV2::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->usedContexts           = [];

        $this->urlService = new UrlService(
            $this->settingsService,
            $this->router,
            $this->tokenFactory,
            $this->salesChannelRepository
        );
    }

    public function testGetPushUrlForOrderPassesTheCallerContextToTheSalesChannelLookup(): void
    {
        $context = $this->createContext([self::DE_LANGUAGE_ID]);
        $this->stubSalesChannelWithDomains([
            ['https://shop.com/de', self::DE_LANGUAGE_ID],
        ]);

        $this->urlService->getPushUrlForOrder($this->createOrder(self::DE_LANGUAGE_ID), $context);

        $this->assertCount(1, $this->usedContexts);
        $this->assertSame(
            $context,
            $this->usedContexts[0],
            'The sales channel lookup must run on the caller context, not on a newly created one'
        );
        $this->assertSame([self::DE_LANGUAGE_ID], $this->usedContexts[0]->getLanguageIdChain());
    }

    public function testGetCancelUrlForOrderPassesTheCallerContextToTheSalesChannelLookup(): void
    {
        $context = $this->createContext([self::EN_LANGUAGE_ID]);
        $this->stubSalesChannelWithDomains([
            ['https://shop.com/en', self::EN_LANGUAGE_ID],
        ]);

        $this->urlService->getCancelUrlForOrder($this->createOrder(self::EN_LANGUAGE_ID), $context);

        $this->assertCount(1, $this->usedContexts);
        $this->assertSame($context, $this->usedContexts[0]);
    }

    /**
     * Two storefronts on different hosts: the base URL the customer actually used decides the
     * domain, so the push lands on the host that owns the session.
     */
    public function testGetPushUrlForOrderSelectsTheDomainMatchingTheBaseUrl(): void
    {
        $this->stubSalesChannelWithDomains([
            ['https://www.shop.com', self::EN_LANGUAGE_ID],
            ['https://kiosk.shop.com', self::EN_LANGUAGE_ID],
        ]);

        $pushUrl = $this->urlService->getPushUrlForOrder(
            $this->createOrder(self::EN_LANGUAGE_ID),
            $this->createContext([self::EN_LANGUAGE_ID]),
            'https://kiosk.shop.com/checkout/finish?orderId=1'
        );

        $this->assertSame('https://kiosk.shop.com/buckaroo/push', $pushUrl);
    }

    /**
     * Same host, language paths: the longest matching domain wins so shop.com/en does not
     * swallow a request that belongs to shop.com/en/b2b.
     */
    public function testGetPushUrlForOrderPrefersTheLongestMatchingDomain(): void
    {
        $this->stubSalesChannelWithDomains([
            ['https://shop.com/en', self::EN_LANGUAGE_ID],
            ['https://shop.com/en/b2b', self::EN_LANGUAGE_ID],
        ]);

        $pushUrl = $this->urlService->getPushUrlForOrder(
            $this->createOrder(self::EN_LANGUAGE_ID),
            $this->createContext([self::EN_LANGUAGE_ID]),
            'https://shop.com/en/b2b/checkout/finish?orderId=1'
        );

        $this->assertSame('https://shop.com/en/b2b/buckaroo/push', $pushUrl);
    }

    /**
     * Without a base URL (capture, refund, pay-per-email) the order language still pins the
     * right domain of the order's sales channel.
     */
    public function testGetPushUrlForOrderFallsBackToTheOrderLanguageDomain(): void
    {
        $this->stubSalesChannelWithDomains([
            ['https://shop.com/en', self::EN_LANGUAGE_ID],
            ['https://shop.com/de', self::DE_LANGUAGE_ID],
        ]);

        $pushUrl = $this->urlService->getPushUrlForOrder(
            $this->createOrder(self::DE_LANGUAGE_ID),
            $this->createContext([self::DE_LANGUAGE_ID])
        );

        $this->assertSame('https://shop.com/de/buckaroo/push', $pushUrl);
    }

    public function testGetCancelUrlForOrderSelectsTheDomainMatchingTheBaseUrl(): void
    {
        $this->stubSalesChannelWithDomains([
            ['https://www.shop.com', self::EN_LANGUAGE_ID],
            ['https://kiosk.shop.com', self::EN_LANGUAGE_ID],
        ]);

        $cancelUrl = $this->urlService->getCancelUrlForOrder(
            $this->createOrder(self::EN_LANGUAGE_ID),
            $this->createContext([self::EN_LANGUAGE_ID]),
            'https://kiosk.shop.com/checkout/finish?orderId=1'
        );

        $this->assertSame('https://kiosk.shop.com/buckaroo/cancel', $cancelUrl);
    }

    /**
     * A sales channel without domains keeps the previous behaviour: fall back on the router.
     */
    public function testGetPushUrlForOrderFallsBackToTheRouterWhenTheSalesChannelHasNoDomains(): void
    {
        $this->stubSalesChannelWithDomains([]);

        $this->router
            ->method('generate')
            ->with('buckaroo.payment.push', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://shop.com/buckaroo/push');

        $pushUrl = $this->urlService->getPushUrlForOrder(
            $this->createOrder(self::EN_LANGUAGE_ID),
            $this->createContext([self::EN_LANGUAGE_ID])
        );

        $this->assertSame('https://shop.com/buckaroo/push', $pushUrl);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $domains url + language id
     */
    private function stubSalesChannelWithDomains(array $domains): void
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SALES_CHANNEL_ID);
        $salesChannel->setUniqueIdentifier(self::SALES_CHANNEL_ID);

        $domainEntities = [];
        foreach ($domains as $index => $domainData) {
            $domainId = str_pad((string) ($index + 1), 32, '0', STR_PAD_LEFT);

            $domain = new SalesChannelDomainEntity();
            $domain->setId($domainId);
            $domain->setUniqueIdentifier($domainId);
            $domain->setUrl($domainData[0]);
            $domain->setLanguageId($domainData[1]);

            $domainEntities[] = $domain;
        }
        $salesChannel->setDomains(new SalesChannelDomainCollection($domainEntities));

        $this->salesChannelRepository
            ->method('search')
            ->willReturnCallback(
                function (Criteria $criteria, Context $context) use ($salesChannel): EntitySearchResult {
                    $this->usedContexts[] = $context;

                    return new EntitySearchResult(
                        'sales_channel',
                        1,
                        new SalesChannelCollection([$salesChannel]),
                        null,
                        $criteria,
                        $context
                    );
                }
            );
    }

    private function createOrder(string $languageId): OrderEntity
    {
        $order = new OrderEntity();
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setLanguageId($languageId);

        return $order;
    }

    /**
     * @param array<int, string> $languageIdChain
     */
    private function createContext(array $languageIdChain): Context
    {
        return new Context(new SystemSource(), [], Defaults::CURRENCY, $languageIdChain);
    }
}
