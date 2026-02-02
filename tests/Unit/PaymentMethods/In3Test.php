<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\In3;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\In3PaymentHandler;

class In3Test extends TestCase
{
    private In3 $payment;

    protected function setUp(): void
    {
        $this->payment = new In3();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->payment);
    }

    public function testGetBuckarooKeyReturnsCorrectKey(): void
    {
        $this->assertSame('capayable', $this->payment->getBuckarooKey());
    }

    public function testGetVersionReturnsCorrectVersion(): void
    {
        $this->assertSame('1', $this->payment->getVersion());
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('In3', $this->payment->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with In3', $this->payment->getDescription());
    }

    public function testGetPaymentHandlerReturnsCorrectHandler(): void
    {
        $this->assertSame(In3PaymentHandler::class, $this->payment->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->payment->getMedia();
        $this->assertStringContainsString('in3.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->payment->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsCorrectType(): void
    {
        $this->assertSame('redirect', $this->payment->getType());
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
        $this->assertSame('buckaroo_capayable', $this->payment->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->payment->getTemplate());
    }
}
