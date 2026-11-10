<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the replacement of Bancontact Client Side Encryption by Hosted Fields.
 *
 * Bancontact CSE was phased out, so its setting and storefront flow are gone.
 * Hosted Fields take its place behind their own setting, which stays disabled by
 * default: they need the Hosted Fields Client ID/Secret, so an update must not
 * switch them on for merchants that do not have those. Disabled, Bancontact uses
 * the standard redirect flow.
 */
class BancontactHostedFieldsConfigTest extends TestCase
{
    private const HOSTED_FIELDS_SETTING = 'bancontactmrcashHostedFields';

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));

        $this->xpath = new DOMXPath($document);
    }

    public function testClientSideEncryptionSettingIsRemoved(): void
    {
        $this->assertNull($this->findInputField('redirectBancontact'));
    }

    public function testHostedFieldsSettingIsABoolInTheBancontactCard(): void
    {
        $nodes = $this->xpath->query(
            '//*[local-name()="card"][*[local-name()="name"][text()="bancontactmrcash"]]'
            . '/*[local-name()="input-field"][*[local-name()="name"][text()="' . self::HOSTED_FIELDS_SETTING . '"]]'
        );

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, 'Expected the setting in the Bancontact card');

        $field = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $field);
        $this->assertSame('bool', $field->getAttribute('type'));
    }

    public function testHostedFieldsSettingIsDisabledByDefault(): void
    {
        $field = $this->findInputField(self::HOSTED_FIELDS_SETTING);
        $this->assertNotNull($field);

        $default = $field->getElementsByTagName('defaultValue')->item(0);
        $this->assertTrue(
            $default === null || in_array(trim((string) $default->textContent), ['false', '0'], true),
            'Bancontact Hosted Fields must not be enabled by default'
        );
    }

    public function testHostedFieldsSettingIsTranslated(): void
    {
        $field = $this->findInputField(self::HOSTED_FIELDS_SETTING);
        $this->assertNotNull($field);

        $locales = [];
        foreach ($field->getElementsByTagName('label') as $label) {
            $locales[] = $label->getAttribute('lang');
        }

        foreach (['en-GB', 'de-DE', 'nl-NL', 'fr-FR'] as $locale) {
            $this->assertContains($locale, $locales, "Missing {$locale} label");
        }
    }

    public function testSubscriberExposesTheHostedFieldsSetting(): void
    {
        $subscriber = $this->read('src/Subscribers/CheckoutConfirmTemplateSubscriber.php');

        $this->assertStringNotContainsString('redirectBancontact', $subscriber);
        $this->assertMatchesRegularExpression(
            "/'bancontactHostedFields'\s*=>\s*\\\$this->canShowBancontactHostedFields\(\\\$salesChannelId\)/",
            $subscriber
        );
        $this->assertMatchesRegularExpression(
            "/getSettingAsBool\('" . self::HOSTED_FIELDS_SETTING . "'/",
            $subscriber
        );
    }

    /**
     * Without the Hosted Fields credentials no token can be fetched, so the fields
     * must not be rendered and Bancontact keeps the redirect.
     */
    public function testHostedFieldsRequireTheHostedFieldsCredentials(): void
    {
        $subscriber = $this->read('src/Subscribers/CheckoutConfirmTemplateSubscriber.php');

        foreach (['clientId', 'clientSecret'] as $credential) {
            $this->assertMatchesRegularExpression(
                "/getSettingAsString\('" . $credential . "',\s*\\\$salesChannelId\)\s*!==\s*''/",
                $subscriber
            );
        }
    }

    /**
     * @dataProvider snippetLocaleProvider
     */
    public function testBancontactLabelsAreTranslated(string $file): void
    {
        /** @var array<string, mixed> $snippets */
        $snippets = json_decode($this->read($file), true, 512, JSON_THROW_ON_ERROR);
        $labels = $snippets['buckaroo']['checkout']['bancontactmrcash'] ?? null;

        $this->assertIsArray($labels);
        foreach (['cardholder', 'cardNumber', 'expiration'] as $key) {
            $this->assertArrayHasKey($key, $labels);
            $this->assertNotSame('', trim((string) $labels[$key]));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function snippetLocaleProvider(): array
    {
        return [
            'en-GB' => ['src/Resources/snippet/en_GB/messages.en-GB.json'],
            'nl-NL' => ['src/Resources/snippet/nl_NL/messages.nl-NL.json'],
            'de-DE' => ['src/Resources/snippet/de_DE/messages.de-DE.json'],
            'fr-FR' => ['src/Resources/snippet/fr_FR/messages.fr-FR.json'],
        ];
    }

    /**
     * @dataProvider checkoutTemplateProvider
     */
    public function testCheckoutRendersBancontactFieldsOnlyWithHostedFields(string $template): void
    {
        $content = $this->read($template);

        $this->assertStringNotContainsString('redirectBancontact', $content);
        $this->assertStringContainsString(
            "buckarooKey == 'bancontactmrcash' and page.extensions.buckaroo.bancontactHostedFields",
            $content
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function checkoutTemplateProvider(): array
    {
        return [
            'payment methods' => ['src/Resources/views/storefront/buckaroo/payment_methods.html.twig'],
            'payment method' => ['src/Resources/views/storefront/component/payment/payment-method.html.twig'],
        ];
    }

    /**
     * Bancontact uses the card Hosted Fields markup without a CVC field: the
     * SDK's BancontactMrCash service refuses to mount one.
     */
    public function testBancontactTemplateRendersHostedFieldsWithoutCvc(): void
    {
        $template = $this->read('src/Resources/views/storefront/buckaroo/payment-methods/bancontactmrcash.html.twig');

        $this->assertStringContainsString('id="bk-hosted-fields-bancontactmrcash"', $template);
        $this->assertStringContainsString('name="buckaroo-token"', $template);

        foreach (['bc-name-wrapper', 'bc-number-wrapper', 'bc-expiry-wrapper'] as $wrapperId) {
            $this->assertStringContainsString('id="' . $wrapperId . '"', $template);
        }

        $this->assertDoesNotMatchRegularExpression('/id="[^"]*cvc[^"]*"/i', $template);
        $this->assertStringNotContainsString('encryptedCardData', $template);
    }

    public function testStorefrontPluginUsesHostedFieldsInsteadOfClientSideEncryption(): void
    {
        $plugin = $this->read('src/Resources/app/storefront/src/bancontact/buckaroo-payment.bancontact.js');

        $this->assertStringNotContainsString('ClientSideEncryption', $plugin);
        $this->assertStringContainsString('extends BuckarooCreditCards', $plugin);
        $this->assertStringContainsString("paymentMethod: 'bancontactmrcash'", $plugin);
        $this->assertStringNotContainsString('Cvc', $plugin);

        $main = $this->read('src/Resources/app/storefront/src/main.js');
        $this->assertStringContainsString(
            "PluginManager.register('BuckarooBanContact', BuckarooBanContact, '#bk-hosted-fields-bancontactmrcash');",
            $main
        );
    }

    private function findInputField(string $name): ?DOMElement
    {
        $nodes = $this->xpath->query(
            '//*[local-name()="input-field"]/*[local-name()="name"][text()="' . $name . '"]/..'
        );

        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }

        $field = $nodes->item(0);

        return $field instanceof DOMElement ? $field : null;
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents($this->pluginPath($relativePath));
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }
}
