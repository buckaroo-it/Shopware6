<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\PaypalPaymentHandler;

class PaypalTest extends TestCase
{
    private Paypal $paypal;

    protected function setUp(): void
    {
        $this->paypal = new Paypal();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->paypal);
    }

    public function testGetBuckarooKeyReturnsPaypal(): void
    {
        $this->assertSame('paypal', $this->paypal->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->paypal->getVersion());
    }

    public function testGetNameReturnsPayPal(): void
    {
        $this->assertSame('PayPal', $this->paypal->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with PayPal', $this->paypal->getDescription());
    }

    public function testGetPaymentHandlerReturnsPaypalPaymentHandler(): void
    {
        $this->assertSame(PaypalPaymentHandler::class, $this->paypal->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->paypal->getMedia();
        $this->assertStringContainsString('paypal.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->paypal->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->paypal->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->paypal->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->paypal->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooPaypal(): void
    {
        $this->assertSame('buckaroo_paypal', $this->paypal->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->paypal->getTemplate());
    }
}
