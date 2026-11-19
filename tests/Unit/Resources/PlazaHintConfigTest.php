<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the links to Buckaroo Plaza in the settings.
 *
 * Shopware 6.7.5+ removes target="_blank" from links in help texts, so a link in a tooltip would
 * replace the admin. The links are therefore shown below the setting by the buckaroo-plaza-hint
 * component, which keeps the target. Help texts must not contain links.
 */
class PlazaHintConfigTest extends TestCase
{
    private const LOCALES = ['en-GB', 'nl-NL', 'de-DE', 'fr-FR'];

    private const COMPONENT = 'buckaroo-plaza-hint';

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load(__DIR__ . '/../../../src/Resources/config/config.xml');

        $this->xpath = new DOMXPath($document);
    }

    public function testEverySettingWithAPlazaPageHasAHint(): void
    {
        $fields = array_map(
            fn (DOMElement $hint) => $this->childValue($hint, 'field'),
            $this->hints()
        );

        $this->assertSame(
            ['websiteKey', 'secretKey', 'clientId', 'clientSecret', 'googlepayGatewayMerchantId'],
            $fields
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hintProvider(): array
    {
        return [
            'store key'                     => ['storeKeyHint'],
            'secret key'                    => ['secretKeyHint'],
            'Hosted Fields client ID'       => ['clientIdHint'],
            'Hosted Fields client secret'   => ['clientSecretHint'],
            'Google Pay gateway merchant ID' => ['googlepayGatewayMerchantIdHint'],
        ];
    }

    /**
     * @dataProvider hintProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hintProvider')]
    public function testHintLinksToPlazaInEveryLanguage(string $name): void
    {
        $hint = $this->hint($name);

        $this->assertStringStartsWith('https://plaza.buckaroo.nl/', (string)$this->childValue($hint, 'url'));

        $labels = $this->labels($hint);
        foreach (self::LOCALES as $locale) {
            $this->assertArrayHasKey($locale, $labels, "$name: missing $locale text");
            $this->assertMatchesRegularExpression('/\{link(:[^}]+)?\}/', $labels[$locale], "$name: $locale has no {link}");

            // A bare {link} takes its text from <linkText>
            if (str_contains($labels[$locale], '{link}')) {
                $this->assertNotSame('', trim((string)$this->childValue($hint, 'linkText')), "$name: {link} needs a linkText");
            }
        }
    }

    /**
     * @dataProvider hintProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hintProvider')]
    public function testHintFollowsItsSetting(string $name): void
    {
        $hint = $this->hint($name);
        $field = $this->childValue($hint, 'field');

        $previous = $hint->previousSibling;
        while ($previous !== null && !$previous instanceof DOMElement) {
            $previous = $previous->previousSibling;
        }

        $this->assertInstanceOf(DOMElement::class, $previous);
        $this->assertSame('input-field', $previous->localName);
        $this->assertSame($field, $this->childValue($previous, 'name'), "$name is shown directly below $field");
    }

    public function testHelpTextsContainNoLinks(): void
    {
        $helpTexts = $this->xpath->query('//*[local-name()="helpText"]');
        $this->assertNotFalse($helpTexts);

        foreach ($helpTexts as $helpText) {
            $this->assertDoesNotMatchRegularExpression(
                '/<a\s|href=/i',
                $helpText->textContent,
                'Links in help texts open in the same tab on Shopware 6.7.5+; use a buckaroo-plaza-hint instead'
            );
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function hints(): array
    {
        $nodes = $this->xpath->query('//*[local-name()="component"][@name="' . self::COMPONENT . '"]');
        $hints = [];
        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $hints[] = $node;
            }
        }

        return $hints;
    }

    private function hint(string $name): DOMElement
    {
        foreach ($this->hints() as $hint) {
            if ($this->childValue($hint, 'name') === $name) {
                return $hint;
            }
        }

        $this->fail("No $name hint in config.xml");
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
     * @return array<string, string>
     */
    private function labels(DOMElement $element): array
    {
        $labels = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'label') {
                $labels[$child->getAttribute('lang')] = $child->textContent;
            }
        }

        return $labels;
    }
}
