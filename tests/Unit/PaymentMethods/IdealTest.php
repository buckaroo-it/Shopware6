<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Ideal;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;

class IdealTest extends TestCase
{
    private Ideal $ideal;

    protected function setUp(): void
    {
        $this->ideal = new Ideal();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->ideal);
    }

    /**
     * Test: it returns correct Buckaroo key
     */
    public function testGetBuckarooKeyReturnsIdeal(): void
    {
        // Act
        $result = $this->ideal->getBuckarooKey();

        // Assert
        $this->assertSame('ideal', $result);
    }

    /**
     * Test: it returns version 2
     */
    public function testGetVersionReturnsTwo(): void
    {
        // Act
        $result = $this->ideal->getVersion();

        // Assert
        $this->assertSame('2', $result);
    }

    /**
     * Test: it returns correct name
     */
    public function testGetNameReturnsIDeal(): void
    {
        // Act
        $result = $this->ideal->getName();

        // Assert
        $this->assertSame('iDEAL | Wero', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->ideal->getDescription();

        // Assert
        $this->assertSame('Pay with iDEAL | Wero', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsIdealPaymentHandler(): void
    {
        // Act
        $result = $this->ideal->getPaymentHandler();

        // Assert
        $this->assertSame(IdealPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->ideal->getMedia();

        // Assert
        $this->assertStringContainsString('ideal-wero.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German and English
     */
    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        // Act
        $result = $this->ideal->getTranslations();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    /**
     * Test: it returns German translation with name and description
     */
    public function testGetTranslationsGermanHasNameAndDescription(): void
    {
        // Act
        $result = $this->ideal->getTranslations();

        // Assert
        $this->assertArrayHasKey('name', $result['de-DE']);
        $this->assertArrayHasKey('description', $result['de-DE']);
        $this->assertSame('iDEAL | Wero', $result['de-DE']['name']);
        $this->assertSame('Bezahlen mit iDEAL | Wero', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation with name and description
     */
    public function testGetTranslationsEnglishHasNameAndDescription(): void
    {
        // Act
        $result = $this->ideal->getTranslations();

        // Assert
        $this->assertArrayHasKey('name', $result['en-GB']);
        $this->assertArrayHasKey('description', $result['en-GB']);
        $this->assertSame('iDEAL | Wero', $result['en-GB']['name']);
        $this->assertSame('Pay with iDEAL | Wero', $result['en-GB']['description']);
    }

    /**
     * Test: it returns direct as type
     */
    public function testGetTypeReturnsDirect(): void
    {
        // Act
        $result = $this->ideal->getType();

        // Assert
        $this->assertSame('direct', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->ideal->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it cannot capture
     */
    public function testCanCaptureReturnsFalse(): void
    {
        // Act
        $result = $this->ideal->canCapture();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns correct technical name
     */
    public function testGetTechnicalNameReturnsBuckarooIdeal(): void
    {
        // Act
        $result = $this->ideal->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_ideal', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->ideal->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
