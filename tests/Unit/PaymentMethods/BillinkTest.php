<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\BillinkPaymentHandler;

class BillinkTest extends TestCase
{
    private Billink $billink;

    protected function setUp(): void
    {
        $this->billink = new Billink();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->billink);
    }

    /**
     * Test: it returns correct Buckaroo key with capital B
     */
    public function testGetBuckarooKeyReturnsBillink(): void
    {
        // Act
        $result = $this->billink->getBuckarooKey();

        // Assert
        $this->assertSame('Billink', $result);
    }

    /**
     * Test: it returns default version from AbstractPayment
     */
    public function testGetVersionReturnsOne(): void
    {
        // Act
        $result = $this->billink->getVersion();

        // Assert
        $this->assertSame('1', $result);
    }

    /**
     * Test: it returns correct name
     */
    public function testGetNameReturnsBillink(): void
    {
        // Act
        $result = $this->billink->getName();

        // Assert
        $this->assertSame('Billink', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->billink->getDescription();

        // Assert
        $this->assertSame('Pay afterwards', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsBillinkPaymentHandler(): void
    {
        // Act
        $result = $this->billink->getPaymentHandler();

        // Assert
        $this->assertSame(BillinkPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->billink->getMedia();

        // Assert
        $this->assertStringContainsString('billink.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German, English, Dutch and French
     */
    public function testGetTranslationsReturnsAllLocales(): void
    {
        // Act
        $result = $this->billink->getTranslations();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
        $this->assertArrayHasKey('nl-NL', $result);
        $this->assertArrayHasKey('fr-FR', $result);
    }

    /**
     * Test: it returns German translation
     */
    public function testGetTranslationsGermanHasCorrectText(): void
    {
        // Act
        $result = $this->billink->getTranslations();

        // Assert
        $this->assertSame('Billink', $result['de-DE']['name']);
        $this->assertSame('Später bezahlen', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation
     */
    public function testGetTranslationsEnglishHasCorrectText(): void
    {
        // Act
        $result = $this->billink->getTranslations();

        // Assert
        $this->assertSame('Billink', $result['en-GB']['name']);
        $this->assertSame('Pay afterwards', $result['en-GB']['description']);
    }

    /**
     * Test: it returns Dutch translation
     */
    public function testGetTranslationsDutchHasCorrectText(): void
    {
        // Act
        $result = $this->billink->getTranslations();

        // Assert
        $this->assertSame('Billink', $result['nl-NL']['name']);
        $this->assertSame('Achteraf betalen', $result['nl-NL']['description']);
    }

    /**
     * Test: it returns French translation
     */
    public function testGetTranslationsFrenchHasCorrectText(): void
    {
        // Act
        $result = $this->billink->getTranslations();

        // Assert
        $this->assertSame('Billink', $result['fr-FR']['name']);
        $this->assertSame('Payer plus tard', $result['fr-FR']['description']);
    }

    /**
     * Test: it returns redirect as type
     */
    public function testGetTypeReturnsRedirect(): void
    {
        // Act
        $result = $this->billink->getType();

        // Assert
        $this->assertSame('redirect', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->billink->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it cannot capture (explicitly set to false)
     */
    public function testCanCaptureReturnsFalse(): void
    {
        // Act
        $result = $this->billink->canCapture();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns correct technical name (preserves capital B)
     */
    public function testGetTechnicalNameReturnsBuckarooBillink(): void
    {
        // Act
        $result = $this->billink->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_Billink', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->billink->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
