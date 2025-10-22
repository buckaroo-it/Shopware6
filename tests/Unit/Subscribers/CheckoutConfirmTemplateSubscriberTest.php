<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\UrlService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;

class CheckoutConfirmTemplateSubscriberTest extends TestCase
{
    private CheckoutConfirmTemplateSubscriber $subscriber;
    private MockObject $paymentMethodRepository;
    private MockObject $settingsService;
    private MockObject $urlService;
    private MockObject $translator;
    private MockObject $payByBankService;
    private MockObject $in3LogoService;
    private MockObject $idealIssuerService;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(SalesChannelRepository::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->urlService = $this->createMock(UrlService::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->payByBankService = $this->createMock(PayByBankService::class);
        $this->in3LogoService = $this->createMock(In3LogoService::class);
        $this->idealIssuerService = $this->createMock(IdealIssuerService::class);

        $this->subscriber = new CheckoutConfirmTemplateSubscriber(
            $this->paymentMethodRepository,
            $this->settingsService,
            $this->urlService,
            $this->translator,
            $this->payByBankService,
            $this->in3LogoService,
            $this->idealIssuerService
        );
    }

    /**
     * Test that Apple Pay cart setting works with string '1'
     */
    public function testApplePayCartWithStringOne(): void
    {
        // Arrange
        $salesChannelId = Uuid::randomHex();
        $event = $this->createCartPageLoadedEvent($salesChannelId);
        
        $this->settingsService
            ->method('getSetting')
            ->willReturnMap([
                ['applepayHostedPaymentPage', $salesChannelId, '1'], // String '1'
                ['applepayShowCart', $salesChannelId, '1'], // String '1'
                ['websiteKey', $salesChannelId, 'test-key'],
                ['paypalExpresslocation', $salesChannelId, []],
                ['idealEnabled', $salesChannelId, '0'],
                ['idealFastCheckoutEnabled', $salesChannelId, '0'],
            ]);

        // Act
        $this->subscriber->addBuckarooToCart($event);

        // Assert
        /** @var BuckarooStruct $extension */
        $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertInstanceOf(BuckarooStruct::class, $extension);
        $this->assertTrue($extension->get('applepayHostedPaymentPage'));
        $this->assertTrue($extension->get('showApplePay'));
    }

    /**
     * Test that Apple Pay cart setting works with integer 1
     */
    public function testApplePayCartWithIntegerOne(): void
    {
        // Arrange
        $salesChannelId = Uuid::randomHex();
        $event = $this->createCartPageLoadedEvent($salesChannelId);
        
        $this->settingsService
            ->method('getSetting')
            ->willReturnMap([
                ['applepayHostedPaymentPage', $salesChannelId, 1], // Integer 1
                ['applepayShowCart', $salesChannelId, 1], // Integer 1
                ['websiteKey', $salesChannelId, 'test-key'],
                ['paypalExpresslocation', $salesChannelId, []],
                ['idealEnabled', $salesChannelId, 0],
                ['idealFastCheckoutEnabled', $salesChannelId, 0],
            ]);

        // Act
        $this->subscriber->addBuckarooToCart($event);

        // Assert
        /** @var BuckarooStruct $extension */
        $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertTrue($extension->get('applepayHostedPaymentPage'));
        $this->assertTrue($extension->get('showApplePay'));
    }

    /**
     * Test that Apple Pay cart setting works with boolean true
     */
    public function testApplePayCartWithBooleanTrue(): void
    {
        // Arrange
        $salesChannelId = Uuid::randomHex();
        $event = $this->createCartPageLoadedEvent($salesChannelId);
        
        $this->settingsService
            ->method('getSetting')
            ->willReturnMap([
                ['applepayHostedPaymentPage', $salesChannelId, true], // Boolean true
                ['applepayShowCart', $salesChannelId, true], // Boolean true
                ['websiteKey', $salesChannelId, 'test-key'],
                ['paypalExpresslocation', $salesChannelId, []],
                ['idealEnabled', $salesChannelId, false],
                ['idealFastCheckoutEnabled', $salesChannelId, false],
            ]);

        // Act
        $this->subscriber->addBuckarooToCart($event);

        // Assert
        /** @var BuckarooStruct $extension */
        $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertTrue($extension->get('applepayHostedPaymentPage'));
        $this->assertTrue($extension->get('showApplePay'));
    }

    /**
     * Test that Apple Pay cart setting is false with string '0'
     */
    public function testApplePayCartWithStringZero(): void
    {
        // Arrange
        $salesChannelId = Uuid::randomHex();
        $event = $this->createCartPageLoadedEvent($salesChannelId);
        
        $this->settingsService
            ->method('getSetting')
            ->willReturnMap([
                ['applepayHostedPaymentPage', $salesChannelId, '0'], // String '0'
                ['applepayShowCart', $salesChannelId, '0'], // String '0'
                ['websiteKey', $salesChannelId, 'test-key'],
                ['paypalExpresslocation', $salesChannelId, []],
                ['idealEnabled', $salesChannelId, '0'],
                ['idealFastCheckoutEnabled', $salesChannelId, '0'],
            ]);

        // Act
        $this->subscriber->addBuckarooToCart($event);

        // Assert
        /** @var BuckarooStruct $extension */
        $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertFalse($extension->get('applepayHostedPaymentPage'));
        $this->assertFalse($extension->get('showApplePay'));
    }

    /**
     * Test that Apple Pay cart setting handles null values with defaults
     */
    public function testApplePayCartWithNullValues(): void
    {
        // Arrange
        $salesChannelId = Uuid::randomHex();
        $event = $this->createCartPageLoadedEvent($salesChannelId);
        
        $this->settingsService
            ->method('getSetting')
            ->willReturnMap([
                ['applepayHostedPaymentPage', $salesChannelId, null], // Null value
                ['applepayShowCart', $salesChannelId, null], // Null value
                ['websiteKey', $salesChannelId, 'test-key'],
                ['paypalExpresslocation', $salesChannelId, []],
                ['idealEnabled', $salesChannelId, null],
                ['idealFastCheckoutEnabled', $salesChannelId, null],
            ]);

        // Act
        $this->subscriber->addBuckarooToCart($event);

        // Assert
        /** @var BuckarooStruct $extension */
        $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
        $this->assertFalse($extension->get('applepayHostedPaymentPage')); // Default false
        $this->assertFalse($extension->get('showApplePay')); // Default false
    }

    /**
     * Test Apple Pay product page with different setting representations
     */
    public function testApplePayProductPageWithDifferentTypes(): void
    {
        // Test cases for different setting types
        $testCases = [
            ['value' => '1', 'expected' => true, 'description' => 'string 1'],
            ['value' => 1, 'expected' => true, 'description' => 'integer 1'],
            ['value' => true, 'expected' => true, 'description' => 'boolean true'],
            ['value' => '0', 'expected' => false, 'description' => 'string 0'],
            ['value' => 0, 'expected' => false, 'description' => 'integer 0'],
            ['value' => false, 'expected' => false, 'description' => 'boolean false'],
            ['value' => null, 'expected' => false, 'description' => 'null value'],
            ['value' => 'true', 'expected' => true, 'description' => 'string true'],
            ['value' => 'false', 'expected' => false, 'description' => 'string false'],
        ];

        foreach ($testCases as $testCase) {
            // Arrange
            $salesChannelId = Uuid::randomHex();
            $event = $this->createProductPageLoadedEvent($salesChannelId);
            
            $this->settingsService
                ->method('getSetting')
                ->willReturnMap([
                    ['applepayShowProduct', $salesChannelId, $testCase['value']],
                    ['applepayHostedPaymentPage', $salesChannelId, $testCase['value']],
                    ['websiteKey', $salesChannelId, 'test-key'],
                    ['paypalExpresslocation', $salesChannelId, []],
                    ['idealEnabled', $salesChannelId, '0'],
                    ['idealFastCheckoutEnabled', $salesChannelId, '0'],
                ]);

            // Act
            $this->subscriber->addBuckarooToProductPage($event);

            // Assert
            /** @var BuckarooStruct $extension */
            $extension = $event->getPage()->getExtension(BuckarooStruct::EXTENSION_NAME);
            
            $this->assertEquals(
                $testCase['expected'],
                $extension->get('applepayShowProduct'),
                "Failed for applepayShowProduct with {$testCase['description']}"
            );
            
            $this->assertEquals(
                $testCase['expected'],
                $extension->get('applepayHostedPaymentPage'),
                "Failed for applepayHostedPaymentPage with {$testCase['description']}"
            );
        }
    }

    /**
     * Test that helper methods correctly cast different types
     */
    public function testHelperMethodTypeCasting(): void
    {
        // Use reflection to test private methods
        $reflection = new \ReflectionClass($this->subscriber);
        $getSettingAsBoolMethod = $reflection->getMethod('getSettingAsBool');
        $getSettingAsBoolMethod->setAccessible(true);
        
        $getSettingAsIntMethod = $reflection->getMethod('getSettingAsInt');
        $getSettingAsIntMethod->setAccessible(true);

        $salesChannelId = Uuid::randomHex();

        // Test boolean casting
        $boolTestCases = [
            [1, true],
            ['1', true],
            [true, true],
            ['true', true],
            ['TRUE', true],
            [0, false],
            ['0', false],
            [false, false],
            ['false', false],
            ['FALSE', false],
            [null, false], // Default
            ['invalid', false], // Default
        ];

        foreach ($boolTestCases as [$input, $expected]) {
            $this->settingsService
                ->expects($this->once())
                ->method('getSetting')
                ->with('testKey', $salesChannelId)
                ->willReturn($input);

            $result = $getSettingAsBoolMethod->invoke($this->subscriber, 'testKey', $salesChannelId);
            $this->assertEquals($expected, $result, "Failed for boolean input: " . var_export($input, true));
        }

        // Test integer casting
        $intTestCases = [
            [1, 1],
            ['1', 1],
            [true, 1],
            [false, 0],
            ['123', 123],
            [456, 456],
            [null, 0], // Default
            ['invalid', 0], // Default
        ];

        foreach ($intTestCases as [$input, $expected]) {
            $this->settingsService
                ->expects($this->once())
                ->method('getSetting')
                ->with('testIntKey', $salesChannelId)
                ->willReturn($input);

            $result = $getSettingAsIntMethod->invoke($this->subscriber, 'testIntKey', $salesChannelId);
            $this->assertEquals($expected, $result, "Failed for integer input: " . var_export($input, true));
        }
    }

    private function createCartPageLoadedEvent(string $salesChannelId): CheckoutCartPageLoadedEvent
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn($salesChannelId);
        
        $page = $this->createMock(CheckoutCartPage::class);
        $page->method('addExtension')->willReturnSelf();
        $page->method('getExtension')->willReturn(new BuckarooStruct());
        
        return new CheckoutCartPageLoadedEvent(
            $page,
            $salesChannelContext,
            $this->createMock(\Symfony\Component\HttpFoundation\Request::class)
        );
    }

    private function createProductPageLoadedEvent(string $salesChannelId): ProductPageLoadedEvent
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn($salesChannelId);
        
        $page = $this->createMock(ProductPage::class);
        $page->method('addExtension')->willReturnSelf();
        $page->method('getExtension')->willReturn(new BuckarooStruct());
        
        return new ProductPageLoadedEvent(
            $page,
            $salesChannelContext,
            $this->createMock(\Symfony\Component\HttpFoundation\Request::class)
        );
    }
}
