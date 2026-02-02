<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\BancontactPaymentHandler;

class BancontactTest extends TestCase
{
    private Bancontact $bancontact;

    protected function setUp(): void
    {
        $this->bancontact = new Bancontact();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->bancontact);
    }

    /**
     * Test: it returns bancontactmrcash as Buckaroo key
     */
    public function testGetBuckarooKeyReturnsBancontactmrcash(): void
    {
        // Act
        $result = $this->bancontact->getBuckarooKey();

        // Assert
        $this->assertSame('bancontactmrcash', $result);
    }

    /**
     * Test: it returns default version from AbstractPayment
     */
    public function testGetVersionReturnsOne(): void
    {
        // Act
        $result = $this->bancontact->getVersion();

        // Assert
        $this->assertSame('1', $result);
    }

    /**
     * Test: it returns correct name
     */
    public function testGetNameReturnsBancontact(): void
    {
        // Act
        $result = $this->bancontact->getName();

        // Assert
        $this->assertSame('Bancontact', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->bancontact->getDescription();

        // Assert
        $this->assertSame('Pay with Bancontact', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsBancontactPaymentHandler(): void
    {
        // Act
        $result = $this->bancontact->getPaymentHandler();

        // Assert
        $this->assertSame(BancontactPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->bancontact->getMedia();

        // Assert
        $this->assertStringContainsString('bancontact.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German and English
     */
    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        // Act
        $result = $this->bancontact->getTranslations();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    /**
     * Test: it returns German translation
     */
    public function testGetTranslationsGermanHasCorrectText(): void
    {
        // Act
        $result = $this->bancontact->getTranslations();

        // Assert
        $this->assertSame('Bancontact', $result['de-DE']['name']);
        $this->assertSame('Bezahlen mit Bancontact', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation
     */
    public function testGetTranslationsEnglishHasCorrectText(): void
    {
        // Act
        $result = $this->bancontact->getTranslations();

        // Assert
        $this->assertSame('Bancontact', $result['en-GB']['name']);
        $this->assertSame('Pay with Bancontact', $result['en-GB']['description']);
    }

    /**
     * Test: it returns redirect as type
     */
    public function testGetTypeReturnsRedirect(): void
    {
        // Act
        $result = $this->bancontact->getType();

        // Assert
        $this->assertSame('redirect', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->bancontact->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it cannot capture
     */
    public function testCanCaptureReturnsFalse(): void
    {
        // Act
        $result = $this->bancontact->canCapture();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns buckaroo_bancontact as technical name (special case handling)
     * This tests the special case in AbstractPayment::getTechnicalName()
     * where bancontactmrcash becomes buckaroo_bancontact
     */
    public function testGetTechnicalNameReturnsBuckarooBancontact(): void
    {
        // Act
        $result = $this->bancontact->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_bancontact', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->bancontact->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
