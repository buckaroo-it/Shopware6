<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The PayPal, Apple Pay and Google Pay express buttons must only be shown
 * when the payment method itself is enabled in the plugin settings.
 */
class CheckoutConfirmTemplateSubscriberExpressButtonsTest extends TestCase
{
    private const SALES_CHANNEL_ID = '55555555555555555555555555555555';

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var PayPalExpressCredentialsService&MockObject */
    private PayPalExpressCredentialsService $paypalExpressCredentials;

    private CheckoutConfirmTemplateSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->paypalExpressCredentials = $this->createMock(PayPalExpressCredentialsService::class);
        $this->paypalExpressCredentials->method('getMerchantId')->willReturn('paypal-merchant-id');

        $this->settingsService->method('getSetting')->willReturnMap([
            ['paypalExpresslocation', self::SALES_CHANNEL_ID, ['product', 'cart', 'checkout']],
            ['applepayGuid', self::SALES_CHANNEL_ID, 'apple-merchant-id'],
            ['applepayExpresslocation', self::SALES_CHANNEL_ID, ['product', 'cart', 'checkout']],
            ['googlepayExpresslocation', self::SALES_CHANNEL_ID, ['product', 'cart', 'checkout']],
        ]);

        $this->subscriber = new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $this->settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->paypalExpressCredentials
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function expressButtonProvider(): array
    {
        $cases = [];
        foreach (['product', 'cart', 'checkout'] as $page) {
            $cases["paypal $page"] = ['showPaypalExpress', 'paypal', $page];
            $cases["applepay $page"] = ['showApplePayExpress', 'applepay', $page];
            $cases["googlepay $page"] = ['showGooglePayExpress', 'googlepay', $page];
        }
        return $cases;
    }

    /**
     * @dataProvider expressButtonProvider
     */
    public function testExpressButtonIsHiddenWhenPaymentMethodIsDisabled(
        string $method,
        string $buckarooKey,
        string $page
    ): void {
        $this->settingsService->method('getEnabled')
            ->with($buckarooKey, self::SALES_CHANNEL_ID)
            ->willReturn(false);

        $this->assertFalse($this->callShow($method, $page));
    }

    /**
     * @dataProvider expressButtonProvider
     */
    public function testExpressButtonIsShownWhenPaymentMethodIsEnabled(
        string $method,
        string $buckarooKey,
        string $page
    ): void {
        $this->settingsService->method('getEnabled')
            ->with($buckarooKey, self::SALES_CHANNEL_ID)
            ->willReturn(true);

        $this->assertTrue($this->callShow($method, $page));
    }

    /**
     * Apple Pay and Google Pay use the same "Display ... button" list as PayPal:
     * only the selected pages show the button, "None" shows it nowhere.
     */
    public function testWalletButtonsOnlyShowOnSelectedPages(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getEnabled')->willReturn(true);
        $settingsService->method('getSetting')->willReturnMap([
            ['applepayGuid', self::SALES_CHANNEL_ID, 'apple-merchant-id'],
            ['applepayExpresslocation', self::SALES_CHANNEL_ID, ['cart']],
            ['googlepayExpresslocation', self::SALES_CHANNEL_ID, ['none']],
        ]);
        $this->subscriber = new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->paypalExpressCredentials
        );

        $this->assertTrue($this->callShow('showApplePayExpress', 'cart'));
        $this->assertFalse($this->callShow('showApplePayExpress', 'product'));
        $this->assertFalse($this->callShow('showApplePayExpress', 'checkout'));

        foreach (['product', 'cart', 'checkout'] as $page) {
            $this->assertFalse($this->callShow('showGooglePayExpress', $page), "Google Pay on $page");
        }
    }

    private function callShow(string $method, string $page): bool
    {
        $reflection = new \ReflectionMethod($this->subscriber, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->subscriber, self::SALES_CHANNEL_ID, $page);
    }
}
