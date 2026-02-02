<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\ApplePayPaymentHandler;

class ApplePayTest extends TestCase
{
    private ApplePay $applePay;

    protected function setUp(): void
    {
        $this->applePay = new ApplePay();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->applePay);
    }

    public function testGetBuckarooKeyReturnsApplepay(): void
    {
        $this->assertSame('applepay', $this->applePay->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->applePay->getVersion());
    }

    public function testGetNameReturnsApplePay(): void
    {
        $this->assertSame('Apple Pay', $this->applePay->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Apple Pay', $this->applePay->getDescription());
    }

    public function testGetPaymentHandlerReturnsApplePayPaymentHandler(): void
    {
        $this->assertSame(ApplePayPaymentHandler::class, $this->applePay->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->applePay->getMedia();
        $this->assertStringContainsString('applepay.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->applePay->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->applePay->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->applePay->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->applePay->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooApplepay(): void
    {
        $this->assertSame('buckaroo_applepay', $this->applePay->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->applePay->getTemplate());
    }
}
