<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\KlarnaPaymentHandler;

class KlarnaTest extends TestCase
{
    private Klarna $klarna;

    protected function setUp(): void
    {
        $this->klarna = new Klarna();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->klarna);
    }

    public function testGetBuckarooKeyReturnsKlarna(): void
    {
        $this->assertSame('klarna', $this->klarna->getBuckarooKey());
    }

    public function testGetVersionReturnsZero(): void
    {
        $this->assertSame('0', $this->klarna->getVersion());
    }

    public function testGetNameReturnsKlarnaPayLater(): void
    {
        $this->assertSame('Klarna Pay later (pay)', $this->klarna->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Klarna Pay later (pay)', $this->klarna->getDescription());
    }

    public function testGetPaymentHandlerReturnsKlarnaPaymentHandler(): void
    {
        $this->assertSame(KlarnaPaymentHandler::class, $this->klarna->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->klarna->getMedia();
        $this->assertStringContainsString('klarna.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->klarna->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->klarna->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->klarna->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->klarna->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooKlarna(): void
    {
        $this->assertSame('buckaroo_klarna', $this->klarna->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->klarna->getTemplate());
    }
}
