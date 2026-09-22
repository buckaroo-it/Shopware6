<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Centralized resolver that determines the language/culture code sent to the
 * Buckaroo payment gateway (Hosted Payment Page, payment instructions and
 * bank transfer instruction e-mails).
 *
 * The behavior is controlled by the general "language" plugin setting:
 *  - browser:          dynamic, based on the customer's browser language (default)
 *  - billing_country:  dynamic, based on the customer's billing country
 *  - sales_channel:    the default language of the active Shopware sales channel
 *  - en|nl|de|fr|es:   a fixed language
 *
 * Unsupported or undetectable languages always fall back to English.
 */
class BuckarooLanguageResolver
{
    public const SETTING_KEY = 'language';

    public const MODE_BROWSER = 'browser';
    public const MODE_BILLING_COUNTRY = 'billing_country';
    public const MODE_SALES_CHANNEL = 'sales_channel';

    public const FALLBACK_CULTURE = 'en-US';

    /**
     * Languages supported by Buckaroo, mapped to the culture code sent to the gateway.
     */
    private const SUPPORTED_CULTURES = [
        'en' => 'en-US',
        'nl' => 'nl-NL',
        'de' => 'de-DE',
        'fr' => 'fr-FR',
        'es' => 'es-ES',
        'it' => 'it-IT',
    ];

    /**
     * Billing country (ISO 3166-1 alpha-2) to language mapping.
     */
    private const COUNTRY_LANGUAGE_MAP = [
        'NL' => 'nl',
        'BE' => 'nl',
        'DE' => 'de',
        'AT' => 'de',
        'CH' => 'de',
        'FR' => 'fr',
        'LU' => 'fr',
        'ES' => 'es',
        'IT' => 'it',
        'GB' => 'en',
        'US' => 'en',
        'IE' => 'en',
    ];

    private SettingsService $settingsService;

    private RequestStack $requestStack;

    private EntityRepository $languageRepository;

    public function __construct(
        SettingsService $settingsService,
        RequestStack $requestStack,
        EntityRepository $languageRepository
    ) {
        $this->settingsService = $settingsService;
        $this->requestStack = $requestStack;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Resolve the Buckaroo culture code (ex. "nl-NL") for the current payment flow.
     *
     * @param SalesChannelContext $context the active sales channel context
     * @param Request|null $request the current request, used for browser language
     *                              detection (defaults to the current request stack request)
     * @param OrderEntity|null $order the order, used for billing country detection
     */
    public function resolveLanguage(
        SalesChannelContext $context,
        ?Request $request = null,
        ?OrderEntity $order = null
    ): string {
        $mode = $this->settingsService->getSetting(self::SETTING_KEY, $context->getSalesChannelId());

        if (!is_string($mode) || $mode === '') {
            $mode = self::MODE_BROWSER;
        }

        // Fixed language selected in the configuration.
        if (isset(self::SUPPORTED_CULTURES[$mode])) {
            return self::SUPPORTED_CULTURES[$mode];
        }

        switch ($mode) {
            case self::MODE_BILLING_COUNTRY:
                return $this->toCulture($this->getLanguageFromBillingCountry($context, $order));
            case self::MODE_SALES_CHANNEL:
                return $this->toCulture($this->getLanguageFromSalesChannel($context));
            case self::MODE_BROWSER:
            default:
                return $this->toCulture(
                    $this->getLanguageFromBrowser($request ?? $this->requestStack->getCurrentRequest())
                );
        }
    }

    /**
     * Detect the language from the browser Accept-Language header.
     */
    private function getLanguageFromBrowser(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        foreach ($request->getLanguages() as $locale) {
            $language = $this->getPrimaryLanguage($locale);
            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Detect the language from the customer's billing country.
     */
    private function getLanguageFromBillingCountry(SalesChannelContext $context, ?OrderEntity $order): ?string
    {
        $iso = $this->getBillingCountryIso($context, $order);

        if ($iso === null) {
            return null;
        }

        return self::COUNTRY_LANGUAGE_MAP[strtoupper($iso)] ?? null;
    }

    private function getBillingCountryIso(SalesChannelContext $context, ?OrderEntity $order): ?string
    {
        if ($order !== null) {
            $billingAddress = $order->getBillingAddress();
            if (
                $billingAddress !== null &&
                $billingAddress->getCountry() !== null &&
                is_string($billingAddress->getCountry()->getIso())
            ) {
                return $billingAddress->getCountry()->getIso();
            }
        }

        $customer = $context->getCustomer();
        if ($customer !== null) {
            $customerAddress = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
            if (
                $customerAddress !== null &&
                $customerAddress->getCountry() !== null &&
                is_string($customerAddress->getCountry()->getIso())
            ) {
                return $customerAddress->getCountry()->getIso();
            }
        }

        return null;
    }

    /**
     * Detect the language configured for the active Shopware sales channel.
     */
    private function getLanguageFromSalesChannel(SalesChannelContext $context): ?string
    {
        $languageId = $context->getSalesChannel()->getLanguageId();
        if (!is_string($languageId) || $languageId === '') {
            $languageId = $context->getContext()->getLanguageId();
        }

        $localeCode = $this->getLocaleCodeByLanguageId($languageId, $context->getContext());
        if ($localeCode === null) {
            return null;
        }

        return $this->getPrimaryLanguage($localeCode);
    }

    private function getLocaleCodeByLanguageId(string $languageId, Context $context): ?string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        /** @var LanguageEntity|null $language */
        $language = $this->languageRepository->search($criteria, $context)->first();

        if ($language === null || $language->getLocale() === null) {
            return null;
        }

        return $language->getLocale()->getCode();
    }

    /**
     * Extract the supported primary language subtag ("nl") from a locale ("nl-NL", "nl_BE").
     */
    private function getPrimaryLanguage(?string $locale): ?string
    {
        if (!is_string($locale) || $locale === '') {
            return null;
        }

        $primary = strtolower((string) preg_replace('/[_-].*$/', '', trim($locale)));

        return isset(self::SUPPORTED_CULTURES[$primary]) ? $primary : null;
    }

    /**
     * Convert a supported language to its Buckaroo culture code, with English fallback.
     */
    private function toCulture(?string $language): string
    {
        if ($language === null) {
            return self::FALLBACK_CULTURE;
        }

        return self::SUPPORTED_CULTURES[$language] ?? self::FALLBACK_CULTURE;
    }
}
