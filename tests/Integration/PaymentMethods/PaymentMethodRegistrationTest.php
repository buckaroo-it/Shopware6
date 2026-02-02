<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\PaymentMethods\Creditcard;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Buckaroo\Shopware6\PaymentMethods\Giftcards;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;

/**
 * Integration test to verify that payment methods are properly configured
 * and can interact with Shopware's payment method system.
 */
class PaymentMethodRegistrationTest extends TestCase
{
    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodCanBeInstantiated(string $className): void
    {
        $paymentMethod = new $className();

        $this->assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasBuckarooKey(string $className): void
    {
        $paymentMethod = new $className();

        $buckarooKey = $paymentMethod->getBuckarooKey();

        $this->assertIsString($buckarooKey);
        $this->assertNotEmpty($buckarooKey);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasVersion(string $className): void
    {
        $paymentMethod = new $className();

        $version = $paymentMethod->getVersion();

        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^[0-2]$/', $version);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasName(string $className): void
    {
        $paymentMethod = new $className();

        $name = $paymentMethod->getName();

        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasDescription(string $className): void
    {
        $paymentMethod = new $className();

        $description = $paymentMethod->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasPaymentHandler(string $className): void
    {
        $paymentMethod = new $className();

        $handlerClass = $paymentMethod->getPaymentHandler();

        $this->assertIsString($handlerClass);
        $this->assertTrue(
            class_exists($handlerClass),
            "Payment handler class $handlerClass should exist"
        );
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasMedia(string $className): void
    {
        $paymentMethod = new $className();

        $media = $paymentMethod->getMedia();

        $this->assertIsString($media);
        $this->assertStringContainsString('.svg', $media);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasTranslations(string $className): void
    {
        $paymentMethod = new $className();

        $translations = $paymentMethod->getTranslations();

        $this->assertIsArray($translations);
        $this->assertArrayHasKey('de-DE', $translations);
        $this->assertArrayHasKey('en-GB', $translations);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasType(string $className): void
    {
        $paymentMethod = new $className();

        $type = $paymentMethod->getType();

        $this->assertIsString($type);
        $this->assertContains($type, ['redirect', 'direct']);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasRefundCapability(string $className): void
    {
        $paymentMethod = new $className();

        $canRefund = $paymentMethod->canRefund();

        $this->assertIsBool($canRefund);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasCaptureCapability(string $className): void
    {
        $paymentMethod = new $className();

        $canCapture = $paymentMethod->canCapture();

        $this->assertIsBool($canCapture);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodHasTechnicalName(string $className): void
    {
        $paymentMethod = new $className();

        $technicalName = $paymentMethod->getTechnicalName();

        $this->assertIsString($technicalName);
        $this->assertStringStartsWith('buckaroo_', $technicalName);
    }

    /**
     * @dataProvider paymentMethodProvider
     */
    public function testPaymentMethodTemplateIsNullable(string $className): void
    {
        $paymentMethod = new $className();

        $template = $paymentMethod->getTemplate();

        $this->assertTrue($template === null || is_string($template));
    }

    /**
     * @return array<string, array<string>>
     */
    public static function paymentMethodProvider(): array
    {
        return [
            'Ideal' => [Ideal::class],
            'Paypal' => [Paypal::class],
            'Creditcard' => [Creditcard::class],
            'Bancontact' => [Bancontact::class],
            'AfterPay' => [AfterPay::class],
            'Billink' => [Billink::class],
            'Klarna' => [Klarna::class],
            'KlarnaKp' => [KlarnaKp::class],
            'Giftcards' => [Giftcards::class],
            'ApplePay' => [ApplePay::class],
        ];
    }
}
