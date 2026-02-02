<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\PayByBank;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\PayByBankPaymentHandler;

class PayByBankTest extends TestCase
{
    private PayByBank $payment;

    protected function setUp(): void
    {
        $this->payment = new PayByBank();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->payment);
    }

    public function testGetBuckarooKeyReturnsCorrectKey(): void
    {
        $this->assertSame('paybybank', $this->payment->getBuckarooKey());
    }

    public function testGetVersionReturnsCorrectVersion(): void
    {
        $this->assertSame('0', $this->payment->getVersion());
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('PayByBank', $this->payment->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with PayByBank', $this->payment->getDescription());
    }

    public function testGetPaymentHandlerReturnsCorrectHandler(): void
    {
        $this->assertSame(PayByBankPaymentHandler::class, $this->payment->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->payment->getMedia();
        $this->assertStringContainsString('paybybank.svg', $result);
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
        $this->assertSame('buckaroo_paybybank', $this->payment->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->payment->getTemplate());
    }
}
