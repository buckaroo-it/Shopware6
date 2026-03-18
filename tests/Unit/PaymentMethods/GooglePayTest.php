<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\GooglePay;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\GooglePayPaymentHandler;

class GooglePayTest extends TestCase
{
    private GooglePay $googlePay;

    protected function setUp(): void
    {
        $this->googlePay = new GooglePay();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->googlePay);
    }

    public function testGetBuckarooKeyReturnsGooglepay(): void
    {
        $this->assertSame('googlepay', $this->googlePay->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->googlePay->getVersion());
    }

    public function testGetNameReturnsGooglePay(): void
    {
        $this->assertSame('Google Pay', $this->googlePay->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Google Pay', $this->googlePay->getDescription());
    }

    public function testGetPaymentHandlerReturnsGooglePayPaymentHandler(): void
    {
        $this->assertSame(GooglePayPaymentHandler::class, $this->googlePay->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->googlePay->getMedia();
        $this->assertStringContainsString('googlepay.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->googlePay->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->googlePay->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->googlePay->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->googlePay->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooGooglepay(): void
    {
        $this->assertSame('buckaroo_googlepay', $this->googlePay->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->googlePay->getTemplate());
    }
}
