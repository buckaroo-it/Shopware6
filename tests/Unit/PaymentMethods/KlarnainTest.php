<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Klarnain;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\KlarnainPaymentHandler;

class KlarnainTest extends TestCase
{
    private Klarnain $klarnain;

    protected function setUp(): void
    {
        $this->klarnain = new Klarnain();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->klarnain);
    }

    public function testGetBuckarooKeyReturnsKlarnain(): void
    {
        $this->assertSame('klarnain', $this->klarnain->getBuckarooKey());
    }

    public function testGetVersionReturnsZero(): void
    {
        $this->assertSame('0', $this->klarnain->getVersion());
    }

    public function testGetNameReturnsKlarnaSliceIt(): void
    {
        $this->assertSame('Klarna Slice it', $this->klarnain->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Klarna Slice it', $this->klarnain->getDescription());
    }

    public function testGetPaymentHandlerReturnsKlarnainPaymentHandler(): void
    {
        $this->assertSame(KlarnainPaymentHandler::class, $this->klarnain->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->klarnain->getMedia();
        $this->assertStringContainsString('klarna.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->klarnain->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->klarnain->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->klarnain->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->klarnain->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooKlarnain(): void
    {
        $this->assertSame('buckaroo_klarnain', $this->klarnain->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->klarnain->getTemplate());
    }
}
