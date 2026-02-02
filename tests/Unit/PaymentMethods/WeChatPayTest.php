<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\WeChatPay;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\WeChatPayPaymentHandler;

class WeChatPayTest extends TestCase
{
    private WeChatPay $weChatPay;

    protected function setUp(): void
    {
        $this->weChatPay = new WeChatPay();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->weChatPay);
    }

    public function testGetBuckarooKeyReturnsWeChatPay(): void
    {
        $this->assertSame('WeChatPay', $this->weChatPay->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->weChatPay->getVersion());
    }

    public function testGetNameReturnsWeChatPay(): void
    {
        $this->assertSame('WeChatPay', $this->weChatPay->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with WeChatPay', $this->weChatPay->getDescription());
    }

    public function testGetPaymentHandlerReturnsWeChatPayPaymentHandler(): void
    {
        $this->assertSame(WeChatPayPaymentHandler::class, $this->weChatPay->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->weChatPay->getMedia();
        $this->assertStringContainsString('wechatpay.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->weChatPay->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->weChatPay->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->weChatPay->canRefund());
    }

    public function testCanCaptureReturnsFalse(): void
    {
        $this->assertFalse($this->weChatPay->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooWeChatPay(): void
    {
        $this->assertSame('buckaroo_WeChatPay', $this->weChatPay->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->weChatPay->getTemplate());
    }
}
