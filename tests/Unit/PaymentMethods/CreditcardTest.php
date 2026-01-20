<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Creditcard;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\CreditcardPaymentHandler;

class CreditcardTest extends TestCase
{
    private Creditcard $creditcard;

    protected function setUp(): void
    {
        $this->creditcard = new Creditcard();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->creditcard);
    }

    /**
     * Test: it returns correct Buckaroo key
     */
    public function testGetBuckarooKeyReturnsCreditcard(): void
    {
        // Act
        $result = $this->creditcard->getBuckarooKey();

        // Assert
        $this->assertSame('creditcard', $result);
    }

    /**
     * Test: it returns version 2
     */
    public function testGetVersionReturnsTwo(): void
    {
        // Act
        $result = $this->creditcard->getVersion();

        // Assert
        $this->assertSame('2', $result);
    }

    /**
     * Test: it returns correct name
     */
    public function testGetNameReturnsCreditAndDebitCard(): void
    {
        // Act
        $result = $this->creditcard->getName();

        // Assert
        $this->assertSame('Credit and debit card', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->creditcard->getDescription();

        // Assert
        $this->assertSame('Pay with Credit or debit card', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsCreditcardPaymentHandler(): void
    {
        // Act
        $result = $this->creditcard->getPaymentHandler();

        // Assert
        $this->assertSame(CreditcardPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->creditcard->getMedia();

        // Assert
        $this->assertStringContainsString('creditcards.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German and English
     */
    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        // Act
        $result = $this->creditcard->getTranslations();

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
        $result = $this->creditcard->getTranslations();

        // Assert
        $this->assertSame('Credit and debit card', $result['de-DE']['name']);
        $this->assertSame('Bezahlen mit Buckaroo-Kartenzahlung', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation
     */
    public function testGetTranslationsEnglishHasCorrectText(): void
    {
        // Act
        $result = $this->creditcard->getTranslations();

        // Assert
        $this->assertSame('Credit and debit card', $result['en-GB']['name']);
        $this->assertSame('Pay with Credit or debit card', $result['en-GB']['description']);
    }

    /**
     * Test: it returns direct as type
     */
    public function testGetTypeReturnsDirect(): void
    {
        // Act
        $result = $this->creditcard->getType();

        // Assert
        $this->assertSame('direct', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->creditcard->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it cannot capture
     */
    public function testCanCaptureReturnsFalse(): void
    {
        // Act
        $result = $this->creditcard->canCapture();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns correct technical name
     */
    public function testGetTechnicalNameReturnsBuckarooCreditcard(): void
    {
        // Act
        $result = $this->creditcard->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_creditcard', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->creditcard->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
