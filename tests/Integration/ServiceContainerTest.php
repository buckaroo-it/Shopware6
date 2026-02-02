<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\CaptureService;
use Buckaroo\Shopware6\Service\RefundService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integration test to verify that all critical services can be instantiated
 * from the Shopware service container with proper dependency injection.
 */
class ServiceContainerTest extends TestCase
{
    private ?ContainerInterface $container = null;

    protected function setUp(): void
    {
        // Get container from global kernel if available
        if (isset($GLOBALS['kernel'])) {
            $this->container = $GLOBALS['kernel']->getContainer();
        }
    }

    public function testContainerIsAvailable(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function testSettingsServiceCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $service = $this->container->get(SettingsService::class);
        $this->assertInstanceOf(SettingsService::class, $service);
    }

    public function testCaptureServiceCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $service = $this->container->get(CaptureService::class);
        $this->assertInstanceOf(CaptureService::class, $service);
    }

    public function testRefundServiceCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $service = $this->container->get(RefundService::class);
        $this->assertInstanceOf(RefundService::class, $service);
    }

    public function testSignatureValidationServiceCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $service = $this->container->get(SignatureValidationService::class);
        $this->assertInstanceOf(SignatureValidationService::class, $service);
    }

    public function testFormatRequestParamServiceCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $service = $this->container->get(FormatRequestParamService::class);
        $this->assertInstanceOf(FormatRequestParamService::class, $service);
    }

    public function testCheckoutConfirmTemplateSubscriberCanBeInstantiated(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $subscriber = $this->container->get(CheckoutConfirmTemplateSubscriber::class);
        $this->assertInstanceOf(CheckoutConfirmTemplateSubscriber::class, $subscriber);
    }

    public function testCheckoutConfirmTemplateSubscriberIsEventSubscriber(): void
    {
        if ($this->container === null) {
            $this->markTestSkipped('Container not available - run with Shopware test kernel');
        }

        $subscriber = $this->container->get(CheckoutConfirmTemplateSubscriber::class);
        $this->assertInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class, $subscriber);
    }

    public function testSubscriberHasCorrectEventSubscriptions(): void
    {
        $events = CheckoutConfirmTemplateSubscriber::getSubscribedEvents();
        
        $this->assertIsArray($events);
        $this->assertNotEmpty($events);
        
        // Verify key events are subscribed
        $this->assertArrayHasKey(
            'Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent',
            $events
        );
        $this->assertArrayHasKey(
            'Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent',
            $events
        );
        $this->assertArrayHasKey(
            'Shopware\Storefront\Page\Product\ProductPageLoadedEvent',
            $events
        );
        $this->assertArrayHasKey(
            'Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent',
            $events
        );
        $this->assertArrayHasKey(
            'Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent',
            $events
        );
    }
}
