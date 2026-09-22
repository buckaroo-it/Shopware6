<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\BuckarooLanguageResolver;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class BuckarooLanguageResolverTest extends TestCase
{
    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var EntityRepository&MockObject */
    private EntityRepository $languageRepository;

    private RequestStack $requestStack;

    private BuckarooLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->languageRepository = $this->createMock(EntityRepository::class);
        $this->requestStack = new RequestStack();

        $this->resolver = new BuckarooLanguageResolver(
            $this->settingsService,
            $this->requestStack,
            $this->languageRepository
        );
    }

    private function configureMode(?string $mode): void
    {
        $this->settingsService
            ->method('getSetting')
            ->with(BuckarooLanguageResolver::SETTING_KEY, 'sales-channel-id')
            ->willReturn($mode);
    }

    private function getSalesChannelContext(?string $salesChannelLanguageId = null): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setUniqueIdentifier('sales-channel-id');
        if ($salesChannelLanguageId !== null) {
            $salesChannel->setLanguageId($salesChannelLanguageId);
        }

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getCustomer')->willReturn(null);

        return $context;
    }

    private function createRequestWithAcceptLanguage(string $acceptLanguage): Request
    {
        $request = Request::create('/');
        $request->headers->set('Accept-Language', $acceptLanguage);

        return $request;
    }

    private function createOrderWithBillingCountry(?string $iso): OrderEntity
    {
        $order = new OrderEntity();
        $order->setUniqueIdentifier('order-id');

        $address = new OrderAddressEntity();
        $address->setUniqueIdentifier('address-id');

        if ($iso !== null) {
            $country = new CountryEntity();
            $country->setUniqueIdentifier('country-id');
            $country->setIso($iso);
            $address->setCountry($country);
        }

        $order->setBillingAddress($address);

        return $order;
    }

    private function mockSalesChannelLocale(?string $localeCode): void
    {
        $language = null;
        if ($localeCode !== null) {
            $locale = new LocaleEntity();
            $locale->setUniqueIdentifier('locale-id');
            $locale->setCode($localeCode);

            $language = new LanguageEntity();
            $language->setUniqueIdentifier('language-id');
            $language->setLocale($locale);
        }

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($language);

        $this->languageRepository->method('search')->willReturn($searchResult);
    }

    /**
     * Fixed language always wins, regardless of browser, country or sales channel.
     */
    public function testFixedLanguageIsAlwaysUsed(): void
    {
        $this->configureMode('es');

        $request = $this->createRequestWithAcceptLanguage('de-DE,de;q=0.9');
        $order = $this->createOrderWithBillingCountry('NL');

        $this->assertSame(
            'es-ES',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request, $order)
        );
    }

    public function testFixedLanguageMapping(): void
    {
        foreach (['en' => 'en-US', 'nl' => 'nl-NL', 'de' => 'de-DE', 'fr' => 'fr-FR', 'es' => 'es-ES'] as $m => $c) {
            $settingsService = $this->createMock(SettingsService::class);
            $settingsService->method('getSetting')->willReturn($m);
            $resolver = new BuckarooLanguageResolver($settingsService, new RequestStack(), $this->languageRepository);

            $this->assertSame($c, $resolver->resolveLanguage($this->getSalesChannelContext()));
        }
    }

    public function testBrowserLanguageIsResolved(): void
    {
        $this->configureMode('browser');

        $request = $this->createRequestWithAcceptLanguage('de-DE,de;q=0.9,en;q=0.8');

        $this->assertSame(
            'de-DE',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request)
        );
    }

    public function testBrowserLanguageWithRegionVariantMapsToSupportedCulture(): void
    {
        $this->configureMode('browser');

        $request = $this->createRequestWithAcceptLanguage('nl-BE,nl;q=0.9');

        $this->assertSame(
            'nl-NL',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request)
        );
    }

    public function testUnsupportedBrowserLanguageFallsBackToEnglish(): void
    {
        $this->configureMode('browser');

        $request = $this->createRequestWithAcceptLanguage('pl-PL,pl;q=0.9');

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request)
        );
    }

    public function testUnsupportedFirstBrowserLanguageUsesNextSupportedOne(): void
    {
        $this->configureMode('browser');

        $request = $this->createRequestWithAcceptLanguage('pl-PL,fr-FR;q=0.8');

        $this->assertSame(
            'fr-FR',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request)
        );
    }

    public function testMissingRequestFallsBackToEnglish(): void
    {
        $this->configureMode('browser');

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext())
        );
    }

    public function testBrowserModeIsUsedWhenSettingIsEmpty(): void
    {
        $this->configureMode(null);

        $request = $this->createRequestWithAcceptLanguage('nl-NL');

        $this->assertSame(
            'nl-NL',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request)
        );
    }

    public function testBillingCountryIsResolvedFromOrder(): void
    {
        $this->configureMode('billing_country');

        $order = $this->createOrderWithBillingCountry('NL');

        $this->assertSame(
            'nl-NL',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), null, $order)
        );
    }

    public function testBillingCountryIgnoresBrowserLanguage(): void
    {
        $this->configureMode('billing_country');

        $request = $this->createRequestWithAcceptLanguage('de-DE');
        $order = $this->createOrderWithBillingCountry('FR');

        $this->assertSame(
            'fr-FR',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), $request, $order)
        );
    }

    public function testUnsupportedBillingCountryFallsBackToEnglish(): void
    {
        $this->configureMode('billing_country');

        $order = $this->createOrderWithBillingCountry('JP');

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), null, $order)
        );
    }

    public function testMissingBillingCountryFallsBackToEnglish(): void
    {
        $this->configureMode('billing_country');

        $order = $this->createOrderWithBillingCountry(null);

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), null, $order)
        );
    }

    public function testEnglishSpeakingBillingCountryResolvesToEnglish(): void
    {
        $this->configureMode('billing_country');

        $order = $this->createOrderWithBillingCountry('GB');

        $this->assertSame(
            'en-US',
            $this->resolver->resolveLanguage($this->getSalesChannelContext(), null, $order)
        );
    }

    public function testSalesChannelLanguageIsResolved(): void
    {
        $this->configureMode('sales_channel');
        $this->mockSalesChannelLocale('fr-FR');

        $this->assertSame(
            'fr-FR',
            $this->resolver->resolveLanguage($this->getSalesChannelContext('language-id'))
        );
    }

    public function testUnsupportedSalesChannelLanguageFallsBackToEnglish(): void
    {
        $this->configureMode('sales_channel');
        $this->mockSalesChannelLocale('pl-PL');

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext('language-id'))
        );
    }

    public function testMissingSalesChannelLanguageFallsBackToEnglish(): void
    {
        $this->configureMode('sales_channel');
        $this->mockSalesChannelLocale(null);

        $this->assertSame(
            BuckarooLanguageResolver::FALLBACK_CULTURE,
            $this->resolver->resolveLanguage($this->getSalesChannelContext('language-id'))
        );
    }
}
