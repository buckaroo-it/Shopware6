<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Belfius;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\BelfiusPaymentHandler;

class BelfiusTest extends TestCase
{
    private Belfius $payment;

    protected function setUp(): void
    {
        $this->payment = new Belfius();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->payment);
    }

    public function testGetBuckarooKeyReturnsCorrectKey(): void
    {
        $this->assertSame('belfius', $this->payment->getBuckarooKey());
    }

    public function testGetVersionReturnsCorrectVersion(): void
    {
        $this->assertSame('0', $this->payment->getVersion());
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('Belfius', $this->payment->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Belfius', $this->payment->getDescription());
    }

    public function testGetPaymentHandlerReturnsCorrectHandler(): void
    {
        $this->assertSame(BelfiusPaymentHandler::class, $this->payment->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->payment->getMedia();
        $this->assertStringContainsString('belfius.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->payment->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsCorrectType(): void
    {
        $this->assertSame('direct', $this->payment->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->payment->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->payment->canCapture());
    }

    public function testGetTechnicalNameReturnsCorrectName(): void
    {
        $this->assertSame('buckaroo_belfius', $this->payment->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->payment->getTemplate());
    }
}
