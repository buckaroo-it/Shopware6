<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the PayPal Express button style settings and their preview.
 *
 * The settings follow "Display PayPal Express button", only show while "Enable PayPal
 * Express" is on, default to PayPal's own look (so an update changes nothing) and reach
 * the storefront button on the product, cart and checkout page. The admin preview keeps
 * its own copy of the setting names and defaults, which must match config.xml.
 */
class PaypalExpressButtonStyleConfigTest extends TestCase
{
    private const LOCALES = ['en-GB', 'nl-NL', 'de-DE', 'fr-FR'];

    private const STYLE_FIELDS = [
        'paypalExpressButtonStyle',
        'paypalExpressButtonShape',
        'paypalExpressButtonType',
    ];

    private const PREVIEW = 'paypalExpressButtonPreview';

    private const CONFIG_CARD = 'src/Resources/app/administration/src/components/buckaroo-config-card/index.js';

    private const PREVIEW_RENDERER =
        'src/Resources/app/administration/src/components/buckaroo-express-button-preview/renderers/paypal.js';

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));

        $this->xpath = new DOMXPath($document);
    }

    public function testSettingsFollowTheExpressPages(): void
    {
        $names = $this->elementNamesOfPaypalCard();
        $pagesIndex = array_search('paypalExpresslocation', $names, true);

        $this->assertIsInt($pagesIndex);
        $this->assertSame(
            [...self::STYLE_FIELDS, self::PREVIEW],
            array_slice($names, $pagesIndex + 1),
            'Button style, shape, type and preview follow "Display PayPal Express button" and close the card'
        );
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function styleFieldProvider(): array
    {
        return [
            'style' => ['paypalExpressButtonStyle', ['gold', 'blue', 'silver', 'white', 'black'], 'gold'],
            'shape' => ['paypalExpressButtonShape', ['rect', 'pill'], 'rect'],
            'type'  => ['paypalExpressButtonType', ['paypal', 'checkout', 'buynow', 'pay'], 'paypal'],
        ];
    }

    /**
     * The option ids are PayPal's style values (as in the WooCommerce plugin), and the
     * defaults are PayPal's own, so the button looks the same after an update.
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

    public function testPreviewIsTheExpressButtonPreviewComponentForPaypal(): void
    {
        $preview = $this->findElement('component', self::PREVIEW);

        $this->assertNotNull($preview, 'Expected a ' . self::PREVIEW . ' component in config.xml');
        $this->assertSame('buckaroo-express-button-preview', $preview->getAttribute('name'));
        $this->assertSame('paypal', $this->childValue($preview, 'method'));
        $this->assertSame('Button preview', $this->translations($preview, 'label')['en-GB'] ?? null);
    }

    public function testAdminOnlyShowsSettingsWhenPaypalExpressIsOn(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::CONFIG_CARD));

        $found = preg_match('/const\s+SWITCH_DEPENDENT_FIELDS\s*=\s*\{([^}]*)\}/', $source, $match);
        $this->assertSame(1, $found, 'SWITCH_DEPENDENT_FIELDS not found in ' . self::CONFIG_CARD);

        preg_match_all('/(\w+)\s*:\s*[\'"](\w+)[\'"]/', $match[1], $pairs, PREG_SET_ORDER);
        $dependentFields = [];
        foreach ($pairs as $pair) {
            $dependentFields[$pair[1]] = $pair[2];
        }

        foreach ([...self::STYLE_FIELDS, self::PREVIEW] as $name) {
            $this->assertSame('paypalExpressEnabled', $dependentFields[$name] ?? null, "$name depends on the switch");
        }
    }

    /**
     * The preview reads the same settings, with the same defaults, as config.xml
     */
    public function testPreviewUsesTheSettingsAndTheirDefaults(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::PREVIEW_RENDERER));

        $expected = [
            'color' => ['paypalExpressButtonStyle', 'gold'],
            'shape' => ['paypalExpressButtonShape', 'rect'],
            'label' => ['paypalExpressButtonType', 'paypal'],
        ];

        $fields = $this->readObject($source, 'fields');
        $defaults = $this->readObject($source, 'defaults');

        foreach ($expected as $styleKey => [$setting, $default]) {
            $this->assertSame($setting, $fields[$styleKey] ?? null, "Preview $styleKey comes from $setting");
            $this->assertSame($default, $defaults[$styleKey] ?? null, "Preview $styleKey defaults to $default");

            $field = $this->findElement('input-field', $setting);
            $this->assertNotNull($field);
            $this->assertSame($default, $this->childValue($field, 'defaultValue'));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function storefrontTemplateProvider(): array
    {
        return [
            'product'  => ['src/Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig'],
            'cart'     => ['src/Resources/views/storefront/page/checkout/cart/index.html.twig'],
            'checkout' => ['src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig'],
        ];
    }

    /**
     * @dataProvider storefrontTemplateProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storefrontTemplateProvider')]
    public function testStorefrontButtonGetsTheStyle(string $template): void
    {
        $source = (string)file_get_contents($this->pluginPath($template));

        $this->assertMatchesRegularExpression(
            '/paypalOptions:\s*buckaroo\.showPaypalExpress\s*\?\s*\{[^}]*style:\s*buckaroo\.paypalButtonStyle,[^}]*\}/',
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
     * Input fields and components of the PayPal card, in order
     *
     * @return list<string|null>
     */
    private function elementNamesOfPaypalCard(): array
    {
        $cards = $this->xpath->query('//*[local-name()="card"]/*[local-name()="name"][text()="paypal"]/..');
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

        preg_match_all('/(\w+)\s*:\s*[\'"](\w+)[\'"]/', $match[1], $pairs, PREG_SET_ORDER);

        $values = [];
        foreach ($pairs as $pair) {
            $values[$pair[1]] = $pair[2];
        }

        return $values;
    }
}
