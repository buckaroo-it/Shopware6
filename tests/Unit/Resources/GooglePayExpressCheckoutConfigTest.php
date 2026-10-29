<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the "Display Google Pay button" setting, which includes the checkout page.
 *
 * The Google Pay express button on the checkout confirm page is driven purely by
 * configuration and template wiring, so this test pins down that contract: the
 * setting must mirror the Apple Pay and PayPal Express equivalents, must stay
 * hidden by default (existing merchants may not get the button switched on by an
 * update) and must reach the shared express button container.
 */
class GooglePayExpressCheckoutConfigTest extends TestCase
{
    private const GOOGLEPAY_CHECKOUT = 'googlepayExpresslocation';
    private const APPLEPAY_CHECKOUT = 'applepayExpresslocation';
    private const PAYPAL_EXPRESS = 'paypalExpresslocation';

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));

        $this->xpath = new DOMXPath($document);
    }

    public function testCheckoutSettingExists(): void
    {
        $this->assertNotNull(
            $this->findInputField(self::GOOGLEPAY_CHECKOUT),
            'Expected a ' . self::GOOGLEPAY_CHECKOUT . ' input field in config.xml'
        );
    }

    public function testCheckoutSettingIsDisabledByDefault(): void
    {
        $field = $this->findInputField(self::GOOGLEPAY_CHECKOUT);

        $this->assertNotNull($field);
        $this->assertSame('multi-select', $field->getAttribute('type'), 'The setting is a list of pages');
        $this->assertSame('["none"]', $this->childValue($field, 'defaultValue'));
        $this->assertContains('checkout', $this->optionIds($field));
    }

    public function testCheckoutSettingUsesSameControlAsApplePayAndPayPal(): void
    {
        $googlePay = $this->findInputField(self::GOOGLEPAY_CHECKOUT);
        $this->assertNotNull($googlePay);

        foreach ([self::APPLEPAY_CHECKOUT, self::PAYPAL_EXPRESS] as $reference) {
            $field = $this->findInputField($reference);
            $this->assertNotNull($field, $reference);

            $this->assertSame(
                $field->getAttribute('type'),
                $googlePay->getAttribute('type'),
                "Google Pay must use the same control type as $reference"
            );
            $this->assertSame(
                $this->childValue($field, 'defaultValue'),
                $this->childValue($googlePay, 'defaultValue')
            );
            $this->assertSame($this->optionIds($field), $this->optionIds($googlePay));
        }
    }

    public function testCheckoutSettingIsTranslated(): void
    {
        $field = $this->findInputField(self::GOOGLEPAY_CHECKOUT);
        $this->assertNotNull($field);

        $labels = $this->labels($field);

        foreach (['en-GB', 'de-DE', 'nl-NL'] as $locale) {
            $this->assertArrayHasKey($locale, $labels, "Missing {$locale} label");
            $this->assertNotSame('', trim($labels[$locale]));
        }

        $this->assertSame('Display Google Pay button', $labels['en-GB']);
    }

    /**
     * The Apple Pay checkout setting is the reference implementation — the
     * Google Pay one is expected to sit in the same place in its own card:
     * last, only followed by the payment fee that closes every card.
     */
    public function testCheckoutSettingIsLastFieldOfGooglePayCard(): void
    {
        $this->assertSame(
            [self::GOOGLEPAY_CHECKOUT, 'googlepayFee'],
            array_slice($this->inputFieldNamesOfCard('googlepay'), -2)
        );
        $this->assertSame(
            [self::APPLEPAY_CHECKOUT, 'applepayFee'],
            array_slice($this->inputFieldNamesOfCard('applepay'), -2)
        );
    }

    /**
     * The express button is rendered through the shared express button container
     * and must be gated on the new setting.
     */
    public function testConfirmPageRendersGooglePayThroughSharedContainer(): void
    {
        $template = (string)file_get_contents($this->pluginPath(
            'src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig'
        ));

        $this->assertStringContainsString(
            '@BuckarooPayments/storefront/buckaroo/express-checkout-buttons.html.twig',
            $template,
            'Checkout must reuse the shared express button container'
        );
        $this->assertStringContainsString(
            'googlePayOptions: buckaroo.showGooglePay ?',
            $template,
            'Google Pay express button must be gated on the showGooglePay flag'
        );
    }

    /**
     * The storefront plugin treats page "checkout" as "Google Pay is the selected
     * payment method" and wires #confirmFormSubmit instead of rendering a button.
     * The express button must therefore run the cart flow, exactly like the
     * Apple Pay express button on the same page.
     */
    public function testConfirmPageGooglePayExpressUsesCartFlow(): void
    {
        $template = (string)file_get_contents($this->pluginPath(
            'src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig'
        ));

        $this->assertMatchesRegularExpression(
            '/googlePayOptions:\s*buckaroo\.showGooglePay\s*\?\s*\{\s*page:\s*"cart"/',
            $template
        );
    }

    /**
     * The storefront plugin resolves its button container with a document-wide
     * getElementById(), so the confirm page must never render that id twice —
     * the express button would otherwise be placed inside the block of the
     * selected Google Pay payment method.
     */
    public function testConfirmPageDeclaresButtonContainerOnlyOnce(): void
    {
        $template = (string)file_get_contents($this->pluginPath(
            'src/Resources/views/storefront/page/checkout/confirm/confirm-payment.html.twig'
        ));

        $this->assertSame(
            0,
            substr_count($template, 'id="google-pay-button-container"'),
            'The confirm page must leave the button container to the shared express template'
        );
    }

    public function testSubscriberExposesCheckoutSettingAsShowGooglePay(): void
    {
        $subscriber = (string)file_get_contents($this->pluginPath(
            'src/Subscribers/CheckoutConfirmTemplateSubscriber.php'
        ));

        $this->assertMatchesRegularExpression(
            "/'showGooglePay'\s*=>\s*\\\$this->showGooglePayExpress\(\\\$salesChannelId,\s*'checkout'\)/",
            $subscriber
        );
        $this->assertStringContainsString(
            "getSetting('" . self::GOOGLEPAY_CHECKOUT . "', \$salesChannelId)",
            $subscriber
        );
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
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

    private function childValue(DOMElement $field, string $tagName): ?string
    {
        foreach ($field->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tagName) {
                return $child->textContent;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function optionIds(DOMElement $field): array
    {
        $ids = [];
        $nodes = $this->xpath->query('.//*[local-name()="option"]/*[local-name()="id"]', $field);

        foreach ($nodes ?: [] as $node) {
            $ids[] = $node->textContent;
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function labels(DOMElement $field): array
    {
        $labels = [];

        foreach ($field->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'label') {
                $labels[$child->getAttribute('lang')] = $child->textContent;
            }
        }

        return $labels;
    }

    /**
     * @return array<int, string|null>
     */
    private function inputFieldNamesOfCard(string $cardName): array
    {
        $cards = $this->xpath->query(
            '//*[local-name()="card"]/*[local-name()="name"][text()="' . $cardName . '"]/..'
        );

        if ($cards === false || $cards->length !== 1) {
            return [];
        }

        $card = $cards->item(0);
        if (!$card instanceof DOMElement) {
            return [];
        }

        $names = [];
        foreach ($card->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'input-field') {
                $names[] = $this->childValue($child, 'name');
            }
        }

        return $names;
    }
}
