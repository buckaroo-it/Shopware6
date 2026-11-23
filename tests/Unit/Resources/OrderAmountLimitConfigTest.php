<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Service\PaymentAmountLimitService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Every Buckaroo payment method has an optional minimum and maximum order amount,
 * as a decimal field without default, so existing installations keep offering their
 * payment methods unchanged after an update.
 */
class OrderAmountLimitConfigTest extends TestCase
{
    private const LOCALES = ['en-GB', 'nl-NL', 'de-DE', 'fr-FR'];

    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $document = new DOMDocument();
        $document->load(\dirname(__DIR__, 3) . '/src/Resources/config/config.xml');

        $this->xpath = new DOMXPath($document);
    }

    public function testEveryPaymentMethodHasBothLimits(): void
    {
        foreach ($this->getPaymentCodes() as $code) {
            foreach ([PaymentAmountLimitService::MIN_SUFFIX, PaymentAmountLimitService::MAX_SUFFIX] as $suffix) {
                $field = $this->findFieldInCard($code, $code . $suffix);
                static::assertNotNull($field, sprintf('%s%s is missing in the %s card', $code, $suffix, $code));
            }
        }
    }

    public function testLimitsAreOptionalDecimalFieldsWithoutDefault(): void
    {
        foreach ($this->getLimitFields() as $name => $field) {
            static::assertSame('float', $field->getAttribute('type'), $name);
            static::assertSame(0, $field->getElementsByTagName('defaultValue')->length, $name . ' has a default');
            static::assertSame(0, $field->getElementsByTagName('required')->length, $name . ' is required');
            static::assertSame('true', $this->childText($field, 'allowEmpty'), $name . ' must allow empty');
            // a <min> would make the number field silently replace a lower value
            static::assertSame(0, $field->getElementsByTagName('min')->length, $name);
        }
    }

    public function testLimitsHaveLabelAndHelpTextInEveryLocale(): void
    {
        foreach ($this->getLimitFields() as $name => $field) {
            foreach (['label', 'helpText'] as $tag) {
                foreach (self::LOCALES as $locale) {
                    $text = $this->xpath->evaluate(
                        sprintf('string(*[local-name()="%s"][@lang="%s"])', $tag, $locale),
                        $field
                    );
                    static::assertIsString($text);
                    static::assertNotSame('', trim($text), sprintf('%s has no %s for %s', $name, $tag, $locale));
                }
            }
        }
    }

    public function testEnglishTextsMatchTheSpecification(): void
    {
        $min = $this->findFieldInCard('afterpay', 'afterpayMinOrderAmount');
        $max = $this->findFieldInCard('afterpay', 'afterpayMaxOrderAmount');
        static::assertNotNull($min);
        static::assertNotNull($max);

        static::assertSame('Minimum order amount', $this->localized($min, 'label'));
        static::assertSame(
            'The payment method shows only for orders with an order amount equal to or greater than the minimum'
            . ' amount.',
            $this->localized($min, 'helpText')
        );
        static::assertSame('Maximum order amount', $this->localized($max, 'label'));
        static::assertSame(
            'The payment method shows only for orders with an order amount equal to or smaller than the maximum'
            . ' amount.',
            $this->localized($max, 'helpText')
        );
    }

    /**
     * Each Buckaroo payment method has a card for its limits, or shares one through
     * PaymentAmountLimitService::LIMIT_SETTING_KEYS.
     */
    public function testEveryGatewayHasLimitSettings(): void
    {
        $codes = $this->getPaymentCodes();

        foreach (GatewayHelper::GATEWAYS as $gatewayClass) {
            $gateway = new $gatewayClass();
            static::assertInstanceOf(PaymentMethodInterface::class, $gateway);

            $settingKey = PaymentAmountLimitService::getSettingKey($gateway->getBuckarooKey());
            static::assertContains($settingKey, $codes, $gateway->getBuckarooKey() . ' has no limit settings');
        }
    }

    /**
     * Cards of payment methods: every card with a "<code>Enabled" setting.
     *
     * @return list<string>
     */
    private function getPaymentCodes(): array
    {
        $codes = [];
        $cards = $this->xpath->query('//*[local-name()="card"]');
        static::assertNotFalse($cards);

        foreach ($cards as $card) {
            static::assertInstanceOf(DOMElement::class, $card);
            $code = $this->childText($card, 'name');
            if ($code !== '' && $this->findFieldInCard($code, $code . 'Enabled') !== null) {
                $codes[] = $code;
            }
        }

        static::assertNotEmpty($codes);

        return $codes;
    }

    /**
     * @return array<string, DOMElement>
     */
    private function getLimitFields(): array
    {
        $fields = [];
        foreach ($this->getPaymentCodes() as $code) {
            foreach ([PaymentAmountLimitService::MIN_SUFFIX, PaymentAmountLimitService::MAX_SUFFIX] as $suffix) {
                $field = $this->findFieldInCard($code, $code . $suffix);
                if ($field !== null) {
                    $fields[$code . $suffix] = $field;
                }
            }
        }

        static::assertNotEmpty($fields);

        return $fields;
    }

    private function findFieldInCard(string $card, string $name): ?DOMElement
    {
        $nodes = $this->xpath->query(
            '//*[local-name()="card"][*[local-name()="name"][text()="' . $card . '"]]'
            . '/*[local-name()="input-field"][*[local-name()="name"][text()="' . $name . '"]]'
        );
        static::assertNotFalse($nodes);

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function childText(DOMElement $element, string $tag): string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tag) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    private function localized(DOMElement $field, string $tag): string
    {
        $text = $this->xpath->evaluate(sprintf('string(*[local-name()="%s"][@lang="en-GB"])', $tag), $field);

        return \is_string($text) ? $text : '';
    }
}
