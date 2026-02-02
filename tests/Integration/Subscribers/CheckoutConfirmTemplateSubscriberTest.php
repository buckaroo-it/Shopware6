<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration\Subscribers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\Country\CountryEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Uuid\Uuid;

class CheckoutConfirmTemplateSubscriberTest extends TestCase
{
    private CheckoutConfirmTemplateSubscriber $subscriber;
    private SalesChannelRepository $paymentMethodRepository;
    private SettingsService $settingsService;
    private UrlService $urlService;
    private TranslatorInterface $translator;
    private PayByBankService $payByBankService;
    private In3LogoService $in3LogoService;
    private IdealIssuerService $idealIssuerService;

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

    public function testImplementsEventSubscriberInterface(): void
    {
        $this->assertInstanceOf(
            \Symfony\Component\EventDispatcher\EventSubscriberInterface::class,
            $this->subscriber
        );
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = CheckoutConfirmTemplateSubscriber::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertNotEmpty($events);

        // Verify all expected events are registered
        $expectedEvents = [
            'Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent',
            'Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent',
            'Shopware\Storefront\Page\Product\ProductPageLoadedEvent',
            'Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent',
            'Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent'
        ];

        foreach ($expectedEvents as $eventClass) {
            $this->assertArrayHasKey($eventClass, $events, "Event $eventClass should be subscribed");
        }
    }

    public function testGetSubscribedEventsHasCorrectMethodNames(): void
    {
        $events = CheckoutConfirmTemplateSubscriber::getSubscribedEvents();

        // Test that methods exist for each event
        $this->assertEquals(
            'addBuckarooExtension',
            $events['Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent']
        );
        $this->assertEquals(
            'addBuckarooExtension',
            $events['Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent']
        );
        $this->assertEquals(
            'addBuckarooToProductPage',
            $events['Shopware\Storefront\Page\Product\ProductPageLoadedEvent']
        );
        $this->assertEquals(
            'addBuckarooToCart',
            $events['Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent']
        );
        $this->assertEquals(
            'addInProgress',
            $events['Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent']
        );
    }

    public function testSubscriberMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->subscriber, 'addBuckarooExtension'));
        $this->assertTrue(method_exists($this->subscriber, 'addBuckarooToProductPage'));
        $this->assertTrue(method_exists($this->subscriber, 'addBuckarooToCart'));
        $this->assertTrue(method_exists($this->subscriber, 'addInProgress'));
    }

    public function testAddBuckarooExtensionRequiresCustomer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot find customer');

        $event = $this->createMock(CheckoutConfirmPageLoadedEvent::class);
        $page = $this->createMock(CheckoutConfirmPage::class);
        $context = $this->createMock(SalesChannelContext::class);
        $request = $this->createMock(Request::class);

        $event->method('getPage')->willReturn($page);
        $event->method('getSalesChannelContext')->willReturn($context);
        $event->method('getRequest')->willReturn($request);

        // Customer is null - should throw exception
        $context->method('getCustomer')->willReturn(null);

        $paymentMethods = new PaymentMethodCollection();
        $page->method('getPaymentMethods')->willReturn($paymentMethods);

        $currency = new CurrencyEntity();
        $currency->setId(Uuid::randomHex());
        $currency->setIsoCode('EUR');
        $currency->setSymbol('€');
        $context->method('getCurrency')->willReturn($currency);

        $this->subscriber->addBuckarooExtension($event);
    }

    public function testIsPayPermMailDisabledInFrontendWhenDisabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->settingsService
            ->expects($this->once())
            ->method('getSetting')
            ->with('payperemailEnabledfrontend', $salesChannelId)
            ->willReturn(false);

        $result = $this->subscriber->isPayPermMailDisabledInFrontend($salesChannelId);

        $this->assertTrue($result);
    }

    public function testIsPayPermMailDisabledInFrontendWhenEnabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->settingsService
            ->expects($this->once())
            ->method('getSetting')
            ->with('payperemailEnabledfrontend', $salesChannelId)
            ->willReturn(true);

        $result = $this->subscriber->isPayPermMailDisabledInFrontend($salesChannelId);

        $this->assertFalse($result);
    }

    public function testIsPayPermMailDisabledInFrontendWithNullSalesChannel(): void
    {
        $this->settingsService
            ->expects($this->once())
            ->method('getSetting')
            ->with('payperemailEnabledfrontend', null)
            ->willReturn(false);

        $result = $this->subscriber->isPayPermMailDisabledInFrontend();

        $this->assertTrue($result);
    }
}
