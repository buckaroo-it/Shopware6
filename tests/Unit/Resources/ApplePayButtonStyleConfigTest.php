<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Apple Pay express button style settings and their preview.
 *
 * The settings follow "Display Apple Pay button" and only show while "Enable Apple Pay express
 * button" is on. Their defaults keep the button as it was before the settings existed
 * (Black, Plain), so an update changes nothing. The options and defaults are those of WooCommerce.
 * They reach the storefront button on the product, cart and checkout page. The admin preview draws
 * the button with the storefront's own button module, whose options must match config.xml.
 */
class ApplePayButtonStyleConfigTest extends TestCase
{
    private const LOCALES = ['en-GB', 'nl-NL', 'de-DE', 'fr-FR'];

    private const STYLE_FIELDS = ['applepayButtonStyle', 'applepayButtonType'];

    private const PREVIEW = 'applepayButtonPreview';

    private const CONFIG_CARD = 'src/Resources/app/administration/src/components/buckaroo-config-card/index.js';

    private const PREVIEW_RENDERER =
        'src/Resources/app/administration/src/components/buckaroo-express-button-preview/renderers/applepay.js';

    private const PREVIEW_COMPONENT =
        'src/Resources/app/administration/src/components/buckaroo-express-button-preview/index.js';

    private const STOREFRONT_PLUGIN = 'src/Resources/app/storefront/src/applepay/applepay.plugin.js';

    private const STOREFRONT_SDK = 'src/Resources/app/storefront/src/applepay/sdk.js';

    private const BUTTON_MODULE = 'src/Resources/app/storefront/src/applepay/button.js';

    /**
     * The buttonstyle and type values of Apple's <apple-pay-button>, as WooCommerce offers them
     */
    private const BUTTON_STYLES = ['black', 'white', 'white-outline'];

    private const BUTTON_TYPES = ['plain', 'buy', 'check-out', 'order', 'book', 'subscribe'];

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));

        $this->xpath = new DOMXPath($document);
    }

    public function testSettingsFollowTheExpressPages(): void
    {
        $names = $this->elementNamesOfApplePayCard();
        $pagesIndex = array_search('applepayExpresslocation', $names, true);

        $this->assertIsInt($pagesIndex);
        $this->assertSame(
            [...self::STYLE_FIELDS, self::PREVIEW],
            array_slice($names, $pagesIndex + 1),
            'Button style, type and preview follow "Display Apple Pay button" and close the card'
        );
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function styleFieldProvider(): array
    {
        return [
            'style' => ['applepayButtonStyle', self::BUTTON_STYLES, 'black'],
            'type'  => ['applepayButtonType', self::BUTTON_TYPES, 'plain'],
        ];
    }

    /**
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

    public function testPreviewIsTheExpressButtonPreviewComponentForApplePay(): void
    {
        $preview = $this->findElement('component', self::PREVIEW);

        $this->assertNotNull($preview, 'Expected a ' . self::PREVIEW . ' component in config.xml');
        $this->assertSame('buckaroo-express-button-preview', $preview->getAttribute('name'));
        $this->assertSame('applepay', $this->childValue($preview, 'method'));
        $this->assertSame('Button preview', $this->translations($preview, 'label')['en-GB'] ?? null);

        $component = (string)file_get_contents($this->pluginPath(self::PREVIEW_COMPONENT));
        $this->assertMatchesRegularExpression('/const\s+RENDERERS\s*=\s*\{[^}]*\bapplepay\b[^}]*\}/', $component);
    }

    public function testAdminOnlyShowsSettingsWhenApplePayExpressIsOn(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::CONFIG_CARD));

        $found = preg_match('/const\s+SWITCH_DEPENDENT_FIELDS\s*=\s*\{([^}]*)\}/', $source, $match);
        $this->assertSame(1, $found, 'SWITCH_DEPENDENT_FIELDS not found in ' . self::CONFIG_CARD);

        $dependentFields = $this->pairs($match[1]);

        foreach ([...self::STYLE_FIELDS, self::PREVIEW] as $name) {
            $this->assertSame(
                'applepayExpressEnabled',
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
        $module = (string)file_get_contents($this->pluginPath(self::BUTTON_MODULE));

        // option => [setting, list in button.js whose first entry is the default]
        $expected = [
            'buttonStyle' => ['applepayButtonStyle', 'BUTTON_STYLES'],
            'buttonType'  => ['applepayButtonType', 'BUTTON_TYPES'],
        ];

        $fields = $this->readObject($source, 'fields');

        foreach ($expected as $option => [$setting, $list]) {
            $this->assertSame($setting, $fields[$option] ?? null, "Preview $option comes from $setting");
            $this->assertMatchesRegularExpression(
                '/defaults:\s*\{[^}]*\b' . $option . ':\s*' . $list . '\[0\],/',
                $source,
                "Preview $option defaults to the first of $list"
            );

            $field = $this->findElement('input-field', $setting);
            $this->assertNotNull($field);
            $this->assertSame(
                $this->readList($module, $list)[0] ?? null,
                $this->childValue($field, 'defaultValue'),
                "The first of $list is the default of $setting in config.xml"
            );
        }
    }

    /**
     * The storefront and the preview draw the button with the same module, which knows every option
     * of config.xml and falls back to the default (the first option)
     */
    public function testStorefrontAndPreviewShareTheButton(): void
    {
        $module = (string)file_get_contents($this->pluginPath(self::BUTTON_MODULE));

        $this->assertSame(self::BUTTON_STYLES, $this->readList($module, 'BUTTON_STYLES'));
        $this->assertSame(self::BUTTON_TYPES, $this->readList($module, 'BUTTON_TYPES'));
        $this->assertStringContainsString(
            'button.setAttribute("buttonstyle", buttonOption(options.buttonStyle, BUTTON_STYLES));',
            $module
        );
        $this->assertStringContainsString(
            'button.setAttribute("type", buttonOption(options.buttonType, BUTTON_TYPES));',
            $module
        );

        $sdk = (string)file_get_contents($this->pluginPath(self::STOREFRONT_SDK));
        $this->assertStringContainsString('import { APPLE_PAY_SDK_URL, createButton } from "./button";', $sdk);
        $this->assertStringContainsString('script.src = APPLE_PAY_SDK_URL;', $sdk);

        $renderer = (string)file_get_contents($this->pluginPath(self::PREVIEW_RENDERER));
        $this->assertMatchesRegularExpression(
            "/import \{[^}]*\bAPPLE_PAY_SDK_URL\b[^}]*\bcreateButton\b[^}]*\} "
                . "from '..\/..\/..\/..\/..\/storefront\/src\/applepay\/button';/",
            $renderer
        );
        $this->assertStringContainsString('createButton({ ...style, locale })', $renderer);

        $plugin = (string)file_get_contents($this->pluginPath(self::STOREFRONT_PLUGIN));
        $this->assertMatchesRegularExpression('/buttonStyle:\s*"black",\s*buttonType:\s*"plain",/', $plugin);
        $this->assertMatchesRegularExpression(
            '/createButton\(\{\s*buttonStyle:\s*this\.options\.buttonStyle,\s*buttonType:\s*this\.options\.buttonType,/',
            $plugin
        );
    }

    /**
     * Like the storefront (cultureCode), the preview shows the button text in the language of the
     * selected sales channel, or of the Administration for "All Sales Channels"
     */
    public function testPreviewUsesTheStorefrontLanguage(): void
    {
        $component = (string)file_get_contents($this->pluginPath(self::PREVIEW_COMPONENT));
        $this->assertStringContainsString('{ locale: this.previewLocale }', $component);

        $renderer = (string)file_get_contents($this->pluginPath(self::PREVIEW_RENDERER));
        $this->assertMatchesRegularExpression('/draw\(container, style, \{ locale \} = \{\}\)/', $renderer);

        // cultureCode is empty in Shopware 6.7; <html lang> is the storefront language there
        $plugin = (string)file_get_contents($this->pluginPath(self::STOREFRONT_PLUGIN));
        $this->assertStringContainsString(
            'locale: this.options.cultureCode || document.documentElement.lang,',
            $plugin
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
                'applePayOptions: buckaroo.applepayShowProduct ?',
            ],
            'cart'     => [
                'src/Resources/views/storefront/page/checkout/cart/index.html.twig',
                'applePayOptions: buckaroo.showApplePay ?',
            ],
            'checkout' => [
                'src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig',
                'applePayOptions: buckaroo.showApplePay ?',
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
            '/' . preg_quote($options, '/') . '\s*\{[^}]*buttonStyle:\s*buckaroo\.applepayButtonStyle,'
                . '\s*buttonType:\s*buckaroo\.applepayButtonType,[^}]*\}/',
            $source
        );

        // The storefront language: languageInfo in Shopware 6.7, where page.header is gone; page.header in 6.5
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($options, '/') . '\s*\{[^}]*cultureCode:\s*context\.languageInfo\.localeCode'
                . ' \?\? page\.header\.activeLanguage\.translationCode\.code,[^}]*\}/',
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
     * Input fields and components of the Apple Pay card, in order
     *
     * @return list<string|null>
     */
    private function elementNamesOfApplePayCard(): array
    {
        $cards = $this->xpath->query('//*[local-name()="card"]/*[local-name()="name"][text()="applepay"]/..');
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
     * Strings of the array literal assigned to the JS constant $name
     *
     * @return list<string>
     */
    private function readList(string $source, string $name): array
    {
        $found = preg_match('/const\s+' . $name . '\s*=\s*\[([^\]]*)\]/', $source, $match);
        $this->assertSame(1, $found, "$name not found in " . self::BUTTON_MODULE);

        preg_match_all('/["\']([\w-]+)["\']/', $match[1], $values);

        return $values[1];
    }

    /**
     * @return array<string, string>
     */
    private function pairs(string $objectBody): array
    {
        preg_match_all('/(\w+)\s*:\s*[\'"]([\w-]+)[\'"]/', $objectBody, $pairs, PREG_SET_ORDER);

        $values = [];
        foreach ($pairs as $pair) {
            $values[$pair[1]] = $pair[2];
        }

        return $values;
    }
}
