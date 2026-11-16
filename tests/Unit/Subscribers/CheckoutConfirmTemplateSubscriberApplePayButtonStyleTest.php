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
 * The Apple Pay button style and type reach the storefront as the values of Apple's
 * <apple-pay-button>, read per sales channel, on the product, cart and checkout page.
 */
class CheckoutConfirmTemplateSubscriberApplePayButtonStyleTest extends TestCase
{
    private const SALES_CHANNEL_ID = '77777777777777777777777777777777';

    private const SUBSCRIBER = 'src/Subscribers/CheckoutConfirmTemplateSubscriber.php';

    /**
     * Nothing saved yet: Black, Plain, as the button was before the settings existed
     */
    public function testDefaultsToBlackPlain(): void
    {
        $this->assertSame(['black', 'plain'], $this->getStyleAndType([]));
    }

    public function testUsesTheSettingsOfTheSalesChannel(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->exactly(2))
            ->method('getSetting')
            ->with($this->anything(), self::SALES_CHANNEL_ID)
            ->willReturnMap([
                ['applepayButtonStyle', self::SALES_CHANNEL_ID, 'white-outline'],
                ['applepayButtonType', self::SALES_CHANNEL_ID, 'book'],
            ]);

        $this->assertSame(['white-outline', 'book'], $this->invokeStyleAndType($settingsService));
    }

    /**
     * Unknown values (also Google Pay's "checkout", which Apple calls "check-out") become the default
     */
    public function testUnknownValuesFallBackToTheDefault(): void
    {
        $this->assertSame(['black', 'plain'], $this->getStyleAndType([
            'applepayButtonStyle' => 'default',
            'applepayButtonType' => 'checkout',
        ]));
        $this->assertSame(['black', 'plain'], $this->getStyleAndType([
            'applepayButtonStyle' => ['white'],
            'applepayButtonType' => '',
        ]));
    }

    /**
     * Every option that can be chosen in config.xml is passed on unchanged
     */
    public function testEveryConfigOptionIsPassedOn(): void
    {
        $document = new DOMDocument();
        $document->load($this->pluginPath('src/Resources/config/config.xml'));
        $xpath = new DOMXPath($document);

        foreach (['applepayButtonStyle' => 0, 'applepayButtonType' => 1] as $setting => $position) {
            $ids = $xpath->query(
                '//*[local-name()="input-field"]/*[local-name()="name"][text()="' . $setting . '"]'
                . '/../*[local-name()="options"]/*[local-name()="option"]/*[local-name()="id"]'
            );
            $this->assertNotFalse($ids);
            $this->assertGreaterThan(0, $ids->length, "$setting has options");

            foreach ($ids as $id) {
                $values = $this->getStyleAndType([$setting => $id->textContent]);
                $this->assertSame($id->textContent, $values[$position], "$setting option {$id->textContent}");
            }
        }
    }

    public function testProductPageGetsStyleAndType(): void
    {
        $page = new ProductPage();

        $this->createSubscriber($this->settingsReturning($this->whiteCheckout()))
            ->addBuckarooToProductPage(new ProductPageLoadedEvent($page, $this->salesChannelContext(), new Request()));

        $this->assertSame('white', $this->extensionValue($page, 'applepayButtonStyle'));
        $this->assertSame('check-out', $this->extensionValue($page, 'applepayButtonType'));
    }

    public function testCartPageGetsStyleAndType(): void
    {
        $page = new CheckoutCartPage();

        $this->createSubscriber($this->settingsReturning($this->whiteCheckout()))
            ->addBuckarooToCart(new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext(), new Request()));

        $this->assertSame('white', $this->extensionValue($page, 'applepayButtonStyle'));
        $this->assertSame('check-out', $this->extensionValue($page, 'applepayButtonType'));
    }

    /**
     * The checkout page handler needs a customer, cart and payment methods, so its extension
     * is checked in the source, as the PayPal button style test does
     */
    public function testCheckoutPageGetsStyleAndType(): void
    {
        $source = (string)file_get_contents($this->pluginPath(self::SUBSCRIBER));

        $found = preg_match(
            '/public function addBuckarooExtension\(.*?\n    (?:public|protected|private) function /s',
            $source,
            $match
        );
        $this->assertSame(1, $found, 'addBuckarooExtension() not found in ' . self::SUBSCRIBER);
        $this->assertMatchesRegularExpression(
            "/'applepayButtonStyle'\s*=>\s*\\\$this->getApplepayButtonStyle\(\\\$salesChannelId\)/",
            $match[0]
        );
        $this->assertMatchesRegularExpression(
            "/'applepayButtonType'\s*=>\s*\\\$this->getApplepayButtonType\(\\\$salesChannelId\)/",
            $match[0]
        );
    }

    /**
     * @return array<string, string>
     */
    private function whiteCheckout(): array
    {
        return ['applepayButtonStyle' => 'white', 'applepayButtonType' => 'check-out'];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{string, string}
     */
    private function getStyleAndType(array $settings): array
    {
        return $this->invokeStyleAndType($this->settingsReturning($settings));
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
     * @return array{string, string}
     */
    private function invokeStyleAndType(SettingsService $settingsService): array
    {
        $subscriber = $this->createSubscriber($settingsService);

        $style = (new \ReflectionMethod($subscriber, 'getApplepayButtonStyle'))
            ->invoke($subscriber, self::SALES_CHANNEL_ID);
        $type = (new \ReflectionMethod($subscriber, 'getApplepayButtonType'))
            ->invoke($subscriber, self::SALES_CHANNEL_ID);

        $this->assertIsString($style);
        $this->assertIsString($type);

        return [$style, $type];
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
