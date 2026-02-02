<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Giftcards;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\GiftcardsPaymentHandler;

class GiftcardsTest extends TestCase
{
    private Giftcards $giftcards;

    protected function setUp(): void
    {
        $this->giftcards = new Giftcards();
    }

    /**
     * Test: it implements PaymentMethodInterface
     */
    public function testImplementsPaymentMethodInterface(): void
    {
        // Assert
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->giftcards);
    }

    /**
     * Test: it returns correct Buckaroo key
     */
    public function testGetBuckarooKeyReturnsGiftcards(): void
    {
        // Act
        $result = $this->giftcards->getBuckarooKey();

        // Assert
        $this->assertSame('giftcards', $result);
    }

    /**
     * Test: it returns version 2
     */
    public function testGetVersionReturnsTwo(): void
    {
        // Act
        $result = $this->giftcards->getVersion();

        // Assert
        $this->assertSame('2', $result);
    }

    /**
     * Test: it returns correct name
     */
    public function testGetNameReturnsGiftcards(): void
    {
        // Act
        $result = $this->giftcards->getName();

        // Assert
        $this->assertSame('Giftcards', $result);
    }

    /**
     * Test: it returns correct description
     */
    public function testGetDescriptionReturnsCorrectText(): void
    {
        // Act
        $result = $this->giftcards->getDescription();

        // Assert
        $this->assertSame('Pay with Giftcards', $result);
    }

    /**
     * Test: it returns correct payment handler class
     */
    public function testGetPaymentHandlerReturnsGiftcardsPaymentHandler(): void
    {
        // Act
        $result = $this->giftcards->getPaymentHandler();

        // Assert
        $this->assertSame(GiftcardsPaymentHandler::class, $result);
    }

    /**
     * Test: it returns media path
     */
    public function testGetMediaReturnsPath(): void
    {
        // Act
        $result = $this->giftcards->getMedia();

        // Assert
        $this->assertStringContainsString('giftcards.svg', $result);
        $this->assertStringContainsString('Resources/views/storefront/buckaroo/payments', $result);
    }

    /**
     * Test: it returns translations with German and English
     */
    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        // Act
        $result = $this->giftcards->getTranslations();

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
        $result = $this->giftcards->getTranslations();

        // Assert
        $this->assertSame('Giftcards', $result['de-DE']['name']);
        $this->assertSame('Bezahlen mit Giftcards', $result['de-DE']['description']);
    }

    /**
     * Test: it returns English translation
     */
    public function testGetTranslationsEnglishHasCorrectText(): void
    {
        // Act
        $result = $this->giftcards->getTranslations();

        // Assert
        $this->assertSame('Giftcards', $result['en-GB']['name']);
        $this->assertSame('Pay with Giftcards', $result['en-GB']['description']);
    }

    /**
     * Test: it returns direct as type
     */
    public function testGetTypeReturnsDirect(): void
    {
        // Act
        $result = $this->giftcards->getType();

        // Assert
        $this->assertSame('direct', $result);
    }

    /**
     * Test: it can refund
     */
    public function testCanRefundReturnsTrue(): void
    {
        // Act
        $result = $this->giftcards->canRefund();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it cannot capture
     */
    public function testCanCaptureReturnsFalse(): void
    {
        // Act
        $result = $this->giftcards->canCapture();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns correct technical name
     */
    public function testGetTechnicalNameReturnsBuckarooGiftcards(): void
    {
        // Act
        $result = $this->giftcards->getTechnicalName();

        // Assert
        $this->assertSame('buckaroo_giftcards', $result);
    }

    /**
     * Test: it returns null template
     */
    public function testGetTemplateReturnsNull(): void
    {
        // Act
        $result = $this->giftcards->getTemplate();

        // Assert
        $this->assertNull($result);
    }
}
