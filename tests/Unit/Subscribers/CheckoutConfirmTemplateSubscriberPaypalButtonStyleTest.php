<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The PayPal Express button style reaches the storefront as PayPal's style option
 * ({color, shape, label}), read per sales channel, on the product, cart and checkout page.
 */
class CheckoutConfirmTemplateSubscriberPaypalButtonStyleTest extends TestCase
{
    private const SALES_CHANNEL_ID = '66666666666666666666666666666666';

    private const SETTINGS = [
        'color' => 'paypalExpressButtonStyle',
        'shape' => 'paypalExpressButtonShape',
        'label' => 'paypalExpressButtonType',
    ];

    private const SUBSCRIBER = 'src/Subscribers/CheckoutConfirmTemplateSubscriber.php';

    /**
     * Nothing saved yet (e.g. right after an update): PayPal's default look
     */
    public function testDefaultsToPaypalsOwnLook(): void
    {
        $this->assertSame(
            ['color' => 'gold', 'shape' => 'rect', 'label' => 'paypal'],
            $this->getStyle([])
        );
    }

    public function testUsesTheSettingsOfTheSalesChannel(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->exactly(3))
            ->method('getSetting')
            ->with($this->anything(), self::SALES_CHANNEL_ID)
            ->willReturnMap([
                ['paypalExpressButtonStyle', self::SALES_CHANNEL_ID, 'black'],
                ['paypalExpressButtonShape', self::SALES_CHANNEL_ID, 'pill'],
                ['paypalExpressButtonType', self::SALES_CHANNEL_ID, 'buynow'],
            ]);

        $this->assertSame(
            ['color' => 'black', 'shape' => 'pill', 'label' => 'buynow'],
            $this->invokeGetStyle($settingsService)
        );
    }

    public function testUnknownValuesFallBackToTheDefault(): void
    {
        $this->assertSame(
            ['color' => 'gold', 'shape' => 'rect', 'label' => 'paypal'],
            $this->getStyle([
                'paypalExpressButtonStyle' => 'purple',
                'paypalExpressButtonShape' => ['pill'],
                'paypalExpressButtonType' => '',
            ])
        );
    }

    /**
     * Every option that can be chosen in config.xml is passed on unchanged
     */
    public function testEveryConfigOptionIsPassedOn(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));
        $xpath = new DOMXPath($document);

        foreach (self::SETTINGS as $styleKey => $setting) {
            $ids = $xpath->query(
                '//*[local-name()="input-field"]/*[local-name()="name"][text()="' . $setting . '"]'
                . '/../*[local-name()="options"]/*[local-name()="option"]/*[local-name()="id"]'
            );
            $this->assertNotFalse($ids);
            $this->assertGreaterThan(0, $ids->length, "$setting has options");

            foreach ($ids as $id) {
                $style = $this->getStyle([$setting => $id->textContent]);
                $this->assertSame($id->textContent, $style[$styleKey], "$setting option {$id->textContent}");
            }
        }
    }

    public function testProductPageGetsTheStyle(): void
    {
        $page = new ProductPage();

        $this->createSubscriber($this->settingsReturning($this->blueRoundedCheckout()))
            ->addBuckarooToProductPage(new ProductPageLoadedEvent($page, $this->salesChannelContext(), new Request()));

        $this->assertSame(
            ['color' => 'blue', 'shape' => 'pill', 'label' => 'checkout'],
            $this->extensionValue($page, 'paypalButtonStyle')
        );
    }

    public function testCartPageGetsTheStyle(): void
    {
        $page = new CheckoutCartPage();

        $this->createSubscriber($this->settingsReturning($this->blueRoundedCheckout()))
            ->addBuckarooToCart(new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext(), new Request()));

        $this->assertSame(
            ['color' => 'blue', 'shape' => 'pill', 'label' => 'checkout'],
            $this->extensionValue($page, 'paypalButtonStyle')
        );
    }

    /**
     * The checkout (and edit order) page handler needs a customer, cart and payment methods,
     * so its extension is checked in the source, as GooglePayExpressCheckoutConfigTest does
     */
    public function testCheckoutPageGetsTheStyle(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::SUBSCRIBER));

        $found = preg_match(
            '/public function addBuckarooExtension\(.*?\n    (?:public|protected|private) function /s',
            $source,
            $match
        );
        $this->assertSame(1, $found, 'addBuckarooExtension() not found in ' . self::SUBSCRIBER);
        $this->assertMatchesRegularExpression(
            "/'paypalButtonStyle'\s*=>\s*\\\$this->getPaypalButtonStyle\(\\\$salesChannelId\)/",
            $match[0]
        );
    }

    /**
     * @return array<string, string>
     */
    private function blueRoundedCheckout(): array
    {
        return [
            'paypalExpressButtonStyle' => 'blue',
            'paypalExpressButtonShape' => 'pill',
            'paypalExpressButtonType' => 'checkout',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, string>
     */
    private function getStyle(array $settings): array
    {
        return $this->invokeGetStyle($this->settingsReturning($settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settingsReturning(array $settings): SettingsService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        return $settingsService;
    }

    /**
     * @return array<string, string>
     */
    private function invokeGetStyle(SettingsService $settingsService): array
    {
        $subscriber = $this->createSubscriber($settingsService);

        $method = new \ReflectionMethod($subscriber, 'getPaypalButtonStyle');
        $method->setAccessible(true);

        /** @var array<string, string> $style */
        $style = $method->invoke($subscriber, self::SALES_CHANNEL_ID);

        return $style;
    }

    private function createSubscriber(SettingsService $settingsService): CheckoutConfirmTemplateSubscriber
    {
        return new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->createMock(PayPalExpressCredentialsService::class)
        );
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $context;
    }

    private function extensionValue(Page $page, string $key): mixed
    {
        $extension = $page->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertInstanceOf(BuckarooStruct::class, $extension);

        return $extension->get($key);
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }
}
