<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Google Pay express button style settings and their preview.
 *
 * The settings follow "Display Google Pay button", only show while "Enable Google Pay express
 * button" is on and have the same options as the WooCommerce plugin. Their defaults keep the
 * button as it was before the settings existed (Black, "Buy with"), so an update changes nothing.
 * They reach the storefront button on the product, cart and checkout page. The admin preview and
 * the storefront plugin keep their own copy of the options and defaults, which must match config.xml.
 */
class GooglePayButtonStyleConfigTest extends TestCase
{
    private const LOCALES = ['en-GB', 'nl-NL', 'de-DE', 'fr-FR'];

    private const STYLE_FIELDS = ['googlepayButtonStyle', 'googlepayButtonType'];

    private const PREVIEW = 'googlepayButtonPreview';

    private const CONFIG_CARD = 'src/Resources/app/administration/src/components/buckaroo-config-card/index.js';

    private const PREVIEW_RENDERER =
        'src/Resources/app/administration/src/components/buckaroo-express-button-preview/renderers/googlepay.js';

    private const PREVIEW_COMPONENT =
        'src/Resources/app/administration/src/components/buckaroo-express-button-preview/index.js';

    private const STOREFRONT_PLUGIN = 'src/Resources/app/storefront/src/googlepay/googlepay.plugin.js';

    private const BUTTON_TYPES = ['plain', 'pay', 'buy', 'checkout', 'order', 'book', 'subscribe'];

    private const BUTTON_LOCALE_HELPER = 'src/Resources/app/storefront/src/googlepay/button-locale.js';

    /**
     * The buttonLocale values Google lists (developers.google.com/pay/api/web/reference/request-objects#ButtonOptions)
     */
    private const GOOGLE_BUTTON_LOCALES = [
        'en', 'ar', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'hr', 'id', 'it', 'ja',
        'ko', 'ms', 'nl', 'no', 'pl', 'pt', 'ru', 'sk', 'sl', 'sr', 'sv', 'th', 'tr', 'uk', 'zh',
    ];

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));

        $this->xpath = new DOMXPath($document);
    }

    public function testSettingsFollowTheExpressPages(): void
    {
        $names = $this->elementNamesOfGooglePayCard();
        $pagesIndex = array_search('googlepayExpresslocation', $names, true);

        $this->assertIsInt($pagesIndex);
        $this->assertSame(
            [...self::STYLE_FIELDS, self::PREVIEW],
            array_slice($names, $pagesIndex + 1),
            'Button style, type and preview follow "Display Google Pay button" and close the card'
        );
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function styleFieldProvider(): array
    {
        return [
            'style' => ['googlepayButtonStyle', ['black', 'white'], 'black'],
            'type'  => ['googlepayButtonType', self::BUTTON_TYPES, 'buy'],
        ];
    }

    /**
     * The option ids are the values of Google's createButton(), as in the WooCommerce plugin.
     * "Default" is gone: the migration turns it into Black.
     *
     * @dataProvider styleFieldProvider
     *
     * @param list<string> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('styleFieldProvider')]
    public function testOptionsAndDefault(string $name, array $options, string $default): void
    {
        $field = $this->findElement('input-field', $name);

        $this->assertNotNull($field, "Expected a $name input field in config.xml");
        $this->assertSame('single-select', $field->getAttribute('type'));
        $this->assertSame($options, $this->optionIds($field));
        $this->assertSame($default, $this->childValue($field, 'defaultValue'));
    }

    public function testSettingsAndPreviewAreTranslated(): void
    {
        $elements = array_map(fn (string $name) => $this->findElement('input-field', $name), self::STYLE_FIELDS);
        $elements[] = $this->findElement('component', self::PREVIEW);

        foreach ($elements as $element) {
            $this->assertNotNull($element);
            $name = $this->childValue($element, 'name');

            foreach (['label', 'helpText'] as $tag) {
                $texts = $this->translations($element, $tag);
                foreach (self::LOCALES as $locale) {
                    $this->assertNotSame('', trim($texts[$locale] ?? ''), "$name: missing $locale $tag");
                }
            }

            foreach ($this->xpath->query('.//*[local-name()="option"]', $element) ?: [] as $option) {
                $this->assertInstanceOf(DOMElement::class, $option);
                $texts = $this->translations($option, 'name');
                foreach (self::LOCALES as $locale) {
                    $this->assertNotSame('', trim($texts[$locale] ?? ''), "$name option: missing $locale name");
                }
            }
        }
    }

    public function testPreviewIsTheExpressButtonPreviewComponentForGooglePay(): void
    {
        $preview = $this->findElement('component', self::PREVIEW);

        $this->assertNotNull($preview, 'Expected a ' . self::PREVIEW . ' component in config.xml');
        $this->assertSame('buckaroo-express-button-preview', $preview->getAttribute('name'));
        $this->assertSame('googlepay', $this->childValue($preview, 'method'));
        $this->assertSame('Button preview', $this->translations($preview, 'label')['en-GB'] ?? null);

        $component = (string)file_get_contents($this->pluginPath(self::PREVIEW_COMPONENT));
        $this->assertMatchesRegularExpression('/const\s+RENDERERS\s*=\s*\{[^}]*\bgooglepay\b[^}]*\}/', $component);
    }

    public function testAdminOnlyShowsSettingsWhenGooglePayExpressIsOn(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::CONFIG_CARD));

        $found = preg_match('/const\s+SWITCH_DEPENDENT_FIELDS\s*=\s*\{([^}]*)\}/', $source, $match);
        $this->assertSame(1, $found, 'SWITCH_DEPENDENT_FIELDS not found in ' . self::CONFIG_CARD);

        $dependentFields = $this->pairs($match[1]);

        foreach ([...self::STYLE_FIELDS, self::PREVIEW] as $name) {
            $this->assertSame(
                'googlepayExpressEnabled',
                $dependentFields[$name] ?? null,
                "$name depends on the switch"
            );
        }
    }

    /**
     * The preview reads the same settings, with the same defaults, as config.xml
     */
    public function testPreviewUsesTheSettingsAndTheirDefaults(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::PREVIEW_RENDERER));

        $expected = [
            'buttonColor' => ['googlepayButtonStyle', 'black'],
            'buttonType'  => ['googlepayButtonType', 'buy'],
        ];

        $fields = $this->readObject($source, 'fields');
        $defaults = $this->readObject($source, 'defaults');

        foreach ($expected as $option => [$setting, $default]) {
            $this->assertSame($setting, $fields[$option] ?? null, "Preview $option comes from $setting");
            $this->assertSame($default, $defaults[$option] ?? null, "Preview $option defaults to $default");

            $field = $this->findElement('input-field', $setting);
            $this->assertNotNull($field);
            $this->assertSame($default, $this->childValue($field, 'defaultValue'));
        }
    }

    /**
     * The storefront plugin accepts every button type of config.xml and falls back to "buy"
     */
    public function testStorefrontPluginKnowsEveryButtonType(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::STOREFRONT_PLUGIN));

        $found = preg_match('/const\s+BUTTON_TYPES\s*=\s*\[([^\]]*)\]/', $source, $match);
        $this->assertSame(1, $found, 'BUTTON_TYPES not found in ' . self::STOREFRONT_PLUGIN);

        preg_match_all('/["\'](\w+)["\']/', $match[1], $types);
        $this->assertSame('buy', $types[1][0] ?? null, 'The first button type is the default');
        $this->assertEqualsCanonicalizing(self::BUTTON_TYPES, $types[1]);

        $this->assertMatchesRegularExpression('/buttonColor:\s*"black",\s*buttonType:\s*"buy",/', $source);
    }

    /**
     * The button shows its text in the language of the storefront (Shopware's <html lang>), and so
     * does the preview: in the language of the selected sales channel, or of the Administration for
     * "All Sales Channels". Both use the same helper, which only passes the languages Google has a
     * button for.
     */
    public function testButtonLanguageFollowsTheStorefront(): void
    {
        $helper = (string)file_get_contents($this->pluginPath(self::BUTTON_LOCALE_HELPER));

        $found = preg_match('/const\s+BUTTON_LOCALES\s*=\s*\[([^\]]*)\]/', $helper, $match);
        $this->assertSame(1, $found, 'BUTTON_LOCALES not found in ' . self::BUTTON_LOCALE_HELPER);
        preg_match_all('/"([a-z]{2})"/', $match[1], $locales);
        $this->assertSame(self::GOOGLE_BUTTON_LOCALES, $locales[1], 'The languages Google lists for buttonLocale');

        $storefront = (string)file_get_contents($this->pluginPath(self::STOREFRONT_PLUGIN));
        $this->assertStringContainsString('import googlePayButtonLocale from "./button-locale";', $storefront);
        $this->assertStringContainsString('googlePayButtonLocale(document.documentElement.lang)', $storefront);
        $this->assertMatchesRegularExpression(
            '/createButton\(\{[^}]*\.\.\.\(buttonLocale \? \{ buttonLocale \} : \{\}\)/',
            $storefront
        );

        $renderer = (string)file_get_contents($this->pluginPath(self::PREVIEW_RENDERER));
        $this->assertStringContainsString(
            "import googlePayButtonLocale from '../../../../../storefront/src/googlepay/button-locale';",
            $renderer
        );
        $this->assertTrue(
            is_file($this->pluginPath(self::BUTTON_LOCALE_HELPER)),
            'The preview imports the storefront helper'
        );

        $component = (string)file_get_contents($this->pluginPath(self::PREVIEW_COMPONENT));
        $this->assertStringContainsString('{ locale: this.previewLocale }', $component);
        $this->assertStringContainsString('return this.storefrontLocale || this.adminLocale;', $component);
        $this->assertStringContainsString("criteria.addAssociation('language.locale');", $component);
        $this->assertStringContainsString("return typeof locale === 'string' ? locale : locale?.value;", $component);

        $card = (string)file_get_contents($this->pluginPath(self::CONFIG_CARD));
        $this->assertStringContainsString(
            'salesChannelId: this.isNotDefaultSalesChannel ? this.currentSalesChannelId : null',
            $card
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function storefrontTemplateProvider(): array
    {
        return [
            'product'  => [
                'src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig',
                'googlePayOptions: buckaroo.googlepayShowProduct ?',
            ],
            'cart'     => [
                'src/Resources/views/storefront/page/checkout/cart/index.html.twig',
                'googlePayOptions: buckaroo.showGooglePay ?',
            ],
            'checkout' => [
                'src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig',
                'googlePayOptions: buckaroo.showGooglePay ?',
            ],
        ];
    }

    /**
     * @dataProvider storefrontTemplateProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storefrontTemplateProvider')]
    public function testStorefrontButtonGetsTheStyleAndType(string $template, string $options): void
    {
        $source = (string)file_get_contents($this->pluginPath($template));

        $this->assertMatchesRegularExpression(
            '/' . preg_quote($options, '/') . '\s*\{[^}]*buttonColor:\s*buckaroo\.googlepayButtonStyle,'
                . '\s*buttonType:\s*buckaroo\.googlepayButtonType,[^}]*\}/',
            $source
        );
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }

    private function findElement(string $tagName, string $name): ?DOMElement
    {
        $nodes = $this->xpath->query(
            '//*[local-name()="' . $tagName . '"]/*[local-name()="name"][text()="' . $name . '"]/..'
        );

        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }

        $element = $nodes->item(0);

        return $element instanceof DOMElement ? $element : null;
    }

    private function childValue(DOMElement $element, string $tagName): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tagName) {
                return $child->textContent;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function optionIds(DOMElement $field): array
    {
        $ids = [];
        foreach ($this->xpath->query('.//*[local-name()="option"]/*[local-name()="id"]', $field) ?: [] as $node) {
            $ids[] = $node->textContent;
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function translations(DOMElement $element, string $tagName): array
    {
        $texts = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tagName) {
                $texts[$child->getAttribute('lang')] = $child->textContent;
            }
        }

        return $texts;
    }

    /**
     * Input fields and components of the Google Pay card, in order
     *
     * @return list<string|null>
     */
    private function elementNamesOfGooglePayCard(): array
    {
        $cards = $this->xpath->query('//*[local-name()="card"]/*[local-name()="name"][text()="googlepay"]/..');
        $card = $cards !== false && $cards->length === 1 ? $cards->item(0) : null;

        if (!$card instanceof DOMElement) {
            return [];
        }

        $names = [];
        foreach ($card->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, ['input-field', 'component'], true)) {
                $names[] = $this->childValue($child, 'name');
            }
        }

        return $names;
    }

    /**
     * Flat "key: 'value'" object literal assigned to $property in a JS source
     *
     * @return array<string, string>
     */
    private function readObject(string $source, string $property): array
    {
        $found = preg_match('/\b' . $property . ':\s*\{([^}]*)\}/', $source, $match);
        $this->assertSame(1, $found, "$property not found in " . self::PREVIEW_RENDERER);

        return $this->pairs($match[1]);
    }

    /**
     * @return array<string, string>
     */
    private function pairs(string $objectBody): array
    {
        preg_match_all('/(\w+)\s*:\s*[\'"](\w+)[\'"]/', $objectBody, $pairs, PREG_SET_ORDER);

        $values = [];
        foreach ($pairs as $pair) {
            $values[$pair[1]] = $pair[2];
        }

        return $values;
    }
}
