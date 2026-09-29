<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use DOMElement;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Guards the "Show Google Pay button on checkout page" setting.
 *
 * The Google Pay express button on the checkout confirm page is driven purely by
 * configuration and template wiring, so this test pins down that contract: the
 * setting must mirror the Apple Pay equivalent, must stay disabled by default
 * (existing merchants may not get the button switched on by an update) and must
 * reach the shared express button container.
 */
class GooglePayExpressCheckoutConfigTest extends TestCase
{
    private const GOOGLEPAY_CHECKOUT = 'googlepayShowCheckout';
    private const APPLEPAY_CHECKOUT = 'applepayShowCheckout';

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
        $this->assertSame('0', $this->childValue($field, 'defaultValue'));
    }

    public function testCheckoutSettingUsesSameControlAsApplePay(): void
    {
        $googlePay = $this->findInputField(self::GOOGLEPAY_CHECKOUT);
        $applePay = $this->findInputField(self::APPLEPAY_CHECKOUT);

        $this->assertNotNull($googlePay);
        $this->assertNotNull($applePay);

        $this->assertSame(
            $applePay->getAttribute('type'),
            $googlePay->getAttribute('type'),
            'Google Pay checkout setting must use the same control type as Apple Pay'
        );
        $this->assertSame(
            $this->optionIds($applePay),
            $this->optionIds($googlePay),
            'Google Pay checkout setting must use the same Yes/No options as Apple Pay'
        );
        $this->assertSame(
            $this->childValue($applePay, 'defaultValue'),
            $this->childValue($googlePay, 'defaultValue')
        );
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

        $this->assertSame('Show Google Pay button on checkout page', $labels['en-GB']);
    }

    /**
     * The Apple Pay checkout setting is the reference implementation — the
     * Google Pay one is expected to sit in the same place in its own card.
     */
    public function testCheckoutSettingIsLastFieldOfGooglePayCard(): void
    {
        $this->assertSame(
            self::GOOGLEPAY_CHECKOUT,
            $this->lastInputFieldNameOfCard('googlepay')
        );
        $this->assertSame(
            self::APPLEPAY_CHECKOUT,
            $this->lastInputFieldNameOfCard('applepay')
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
            "/'showGooglePay'\s*=>\s*\\\$this->getSettingAsBool\('" . self::GOOGLEPAY_CHECKOUT . "'/",
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

        foreach ($field->getElementsByTagName('option') as $option) {
            foreach ($option->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'id') {
                    $ids[] = $child->textContent;
                }
            }
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

    private function lastInputFieldNameOfCard(string $cardName): ?string
    {
        $cards = $this->xpath->query(
            '//*[local-name()="card"]/*[local-name()="name"][text()="' . $cardName . '"]/..'
        );

        if ($cards === false || $cards->length !== 1) {
            return null;
        }

        $card = $cards->item(0);
        if (!$card instanceof DOMElement) {
            return null;
        }

        $name = null;
        foreach ($card->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'input-field') {
                $name = $this->childValue($child, 'name');
            }
        }

        return $name;
    }
}
