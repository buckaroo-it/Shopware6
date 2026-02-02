<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Buckaroo\Shopware6\PaymentMethods\PaymentMethodInterface;
use Buckaroo\Shopware6\Handlers\KlarnaKpPaymentHandler;

class KlarnaKpTest extends TestCase
{
    private KlarnaKp $klarnaKp;

    protected function setUp(): void
    {
        $this->klarnaKp = new KlarnaKp();
    }

    public function testImplementsPaymentMethodInterface(): void
    {
        $this->assertInstanceOf(PaymentMethodInterface::class, $this->klarnaKp);
    }

    public function testGetBuckarooKeyReturnsKlarnakp(): void
    {
        $this->assertSame('klarnakp', $this->klarnaKp->getBuckarooKey());
    }

    public function testGetVersionReturnsOne(): void
    {
        $this->assertSame('1', $this->klarnaKp->getVersion());
    }

    public function testGetNameReturnsKlarnaPayLaterAuthorizeCapture(): void
    {
        $this->assertSame('Klarna Pay later (authorize/capture)', $this->klarnaKp->getName());
    }

    public function testGetDescriptionReturnsCorrectText(): void
    {
        $this->assertSame('Pay with Klarna', $this->klarnaKp->getDescription());
    }

    public function testGetPaymentHandlerReturnsKlarnaKpPaymentHandler(): void
    {
        $this->assertSame(KlarnaKpPaymentHandler::class, $this->klarnaKp->getPaymentHandler());
    }

    public function testGetMediaReturnsPath(): void
    {
        $result = $this->klarnaKp->getMedia();
        $this->assertStringContainsString('klarna.svg', $result);
    }

    public function testGetTranslationsReturnsGermanAndEnglish(): void
    {
        $result = $this->klarnaKp->getTranslations();
        $this->assertArrayHasKey('de-DE', $result);
        $this->assertArrayHasKey('en-GB', $result);
    }

    public function testGetTypeReturnsRedirect(): void
    {
        $this->assertSame('redirect', $this->klarnaKp->getType());
    }

    public function testCanRefundReturnsTrue(): void
    {
        $this->assertTrue($this->klarnaKp->canRefund());
    }

    public function testCanCaptureReturnsTrue(): void
    {
        $this->assertTrue($this->klarnaKp->canCapture());
    }

    public function testGetTechnicalNameReturnsBuckarooKlarnakp(): void
    {
        $this->assertSame('buckaroo_klarnakp', $this->klarnaKp->getTechnicalName());
    }

    public function testGetTemplateReturnsNull(): void
    {
        $this->assertNull($this->klarnaKp->getTemplate());
    }
}
