<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\Alipay;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\AlipayPaymentHandler;

class AlipayTest extends TestCase
{
    private Alipay $alipay;

    protected function setUp(): void
    {
        $this->alipay = new Alipay();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->alipay);
    }

    public function testGetBuckarooKeyReturnsAlipayWithCapitalA(): void
    {
        $this->assertSame('Alipay', $this->alipay->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->alipay->getVersion());
    }

    public function testGetNameReturnsAlipay(): void
    {
        $this->assertSame('Alipay', $this->alipay->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Alipay', $this->alipay->getDescription());
    }

    public function testGetPaymentHandlerReturnsAlipayPaymentHandler(): void
    {
        $this->assertSame(AlipayPaymentHandler::class, $this->alipay->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->alipay->getMedia();
        $this->assertStringContainsString('alipay.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->alipay->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->alipay->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->alipay->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->alipay->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooAlipay(): void
    {
        $this->assertSame('buckaroo_Alipay', $this->alipay->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->alipay->getTemplate());
    }
}
