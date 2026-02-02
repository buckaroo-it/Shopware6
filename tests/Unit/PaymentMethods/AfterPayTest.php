<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;

class AfterPayTest extends TestCase
{
    private AfterPay $afterPay;

    protected function setUp(): void
    {
        $this->afterPay = new AfterPay();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->afterPay);
    }

    /**
     * Test: it returns correct Buckaroo key
     */
    public function testGetBuckarooKeyReturnsAfterpay(): void
    {
        // Act
        $result = $this->afterPay->getBuckarooKey();

        // Assert
        $this->assertSame('afterpay', $result);
    }

    /**
     * Test: it returns default version from AbstractPayment
     */
    public function testGetVersionReturnsOne(): void
    {
        // Act
        $result = $this->afterPay->getVersion();

        // Assert
        $this->assertSame('1', $result);
    }

    /**
     * Test: it returns correct name (Riverty)
     */
    public function testGetNameReturnsRiverty(): void
    {
        // Act
        $result = $this->afterPay->getName();

        // Assert
        $this->assertSame('Riverty', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->afterPay->getDescription();

        // Assert
        $this->assertSame('Pay with Riverty', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsAfterPayPaymentHandler(): void
    {
        // Act
        $result = $this->afterPay->getPaymentHandler();

        // Assert
        $this->assertSame(AfterPayPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->afterPay->getMedia();

        // Assert
        $this->assertStringContainsString('afterpay.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German and English
     */
    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        // Act
        $result = $this->afterPay->getTranslations();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    /**
     * Test: it returns German translation with Riverty name
     */
    public function testGetTranslationsGermanHasRivertyName(): void
    {
        // Act
        $result = $this->afterPay->getTranslations();

        // Assert
        $this->assertSame('Riverty', $result['de-DE']['name']);
        $this->assertSame('Bezahlen mit Riverty', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation with Riverty name
     */
    public function testGetTranslationsEnglishHasRivertyName(): void
    {
        // Act
        $result = $this->afterPay->getTranslations();

        // Assert
        $this->assertSame('Riverty', $result['en-GB']['name']);
        $this->assertSame('Pay with Riverty', $result['en-GB']['description']);
    }

    /**
     * Test: it returns redirect as type (from AbstractPayment)
     */
    public function testGetTypeReturnsRedirect(): void
    {
        // Act
        $result = $this->afterPay->getType();

        // Assert
        $this->assertSame('redirect', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->afterPay->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it can capture (overridden from AbstractPayment)
     */
    public function testCanCaptureReturnsTrue(): void
    {
        // Act
        $result = $this->afterPay->canCapture();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns correct technical name
     */
    public function testGetTechnicalNameReturnsBuckarooAfterpay(): void
    {
        // Act
        $result = $this->afterPay->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_afterpay', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->afterPay->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
