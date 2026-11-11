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
 * The Google Pay button colour and type reach the storefront as the values of Google's
 * createButton(), read per sales channel, on the product, cart and checkout page.
 */
class CheckoutConfirmTemplateSubscriberGooglePayButtonStyleTest extends TestCase
{
    private const SALES_CHANNEL_ID = '77777777777777777777777777777777';

    private const SUBSCRIBER = 'src/Subscribers/CheckoutConfirmTemplateSubscriber.php';

    /**
     * Nothing saved yet: Black, "Buy with", as before the setting existed
     */
    public function testDefaultsToBlackBuyWith(): void
    {
        $this->assertSame(['black', 'buy'], $this->getStyleAndType([]));
    }

    public function testUsesTheSettingsOfTheSalesChannel(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->exactly(2))
            ->method('getSetting')
            ->with($this->anything(), self::SALES_CHANNEL_ID)
            ->willReturnMap([
                ['googlepayButtonStyle', self::SALES_CHANNEL_ID, 'white'],
                ['googlepayButtonType', self::SALES_CHANNEL_ID, 'book'],
            ]);

        $this->assertSame(['white', 'book'], $this->invokeStyleAndType($settingsService));
    }

    /**
     * The former "default" (in case the migration has not run) and unknown values become the default
     */
    public function testFormerAndUnknownValuesFallBackToTheDefault(): void
    {
        $this->assertSame(['black', 'buy'], $this->getStyleAndType([
            'googlepayButtonStyle' => 'default',
            'googlepayButtonType' => 'long',
        ]));
        $this->assertSame(['black', 'buy'], $this->getStyleAndType([
            'googlepayButtonStyle' => ['white'],
            'googlepayButtonType' => '',
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

        foreach (['googlepayButtonStyle' => 0, 'googlepayButtonType' => 1] as $setting => $position) {
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

        $this->assertSame('white', $this->extensionValue($page, 'googlepayButtonStyle'));
        $this->assertSame('checkout', $this->extensionValue($page, 'googlepayButtonType'));
    }

    public function testCartPageGetsStyleAndType(): void
    {
        $page = new CheckoutCartPage();

        $this->createSubscriber($this->settingsReturning($this->whiteCheckout()))
            ->addBuckarooToCart(new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext(), new Request()));

        $this->assertSame('white', $this->extensionValue($page, 'googlepayButtonStyle'));
        $this->assertSame('checkout', $this->extensionValue($page, 'googlepayButtonType'));
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
            "/'googlepayButtonStyle'\s*=>\s*\\\$this->getGooglepayButtonStyle\(\\\$salesChannelId\)/",
            $match[0]
        );
        $this->assertMatchesRegularExpression(
            "/'googlepayButtonType'\s*=>\s*\\\$this->getGooglepayButtonType\(\\\$salesChannelId\)/",
            $match[0]
        );
    }

    /**
     * @return array<string, string>
     */
    private function whiteCheckout(): array
    {
        return ['googlepayButtonStyle' => 'white', 'googlepayButtonType' => 'checkout'];
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

        $style = (new \ReflectionMethod($subscriber, 'getGooglepayButtonStyle'))
            ->invoke($subscriber, self::SALES_CHANNEL_ID);
        $type = (new \ReflectionMethod($subscriber, 'getGooglepayButtonType'))
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
