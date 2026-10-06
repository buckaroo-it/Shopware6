<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * The express checkout endpoints resolve their payment method through
 * getValidPaymentMethod(); a method disabled in the plugin settings must not
 * be returned, so no express order can be placed with it.
 */
class AbstractPaymentControllerValidPaymentMethodTest extends TestCase
{
    private const SALES_CHANNEL_ID = '55555555555555555555555555555555';

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var SalesChannelRepository&MockObject */
    private SalesChannelRepository $paymentMethodRepository;

    /** @var SalesChannelContext&MockObject */
    private SalesChannelContext $salesChannelContext;

    private AbstractPaymentController $controller;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->paymentMethodRepository = $this->createMock(SalesChannelRepository::class);
        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);
        $this->salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $this->controller = new class (
            $this->createMock(CartService::class),
            $this->createMock(CustomerService::class),
            $this->createMock(OrderService::class),
            $this->settingsService,
            $this->paymentMethodRepository
        ) extends AbstractPaymentController {
        };
    }

    public function testReturnsPaymentMethodWhenEnabled(): void
    {
        $paymentMethod = $this->givenPaymentMethod(['buckaroo_key' => 'paypal']);
        $this->settingsService->method('getEnabled')
            ->with('paypal', self::SALES_CHANNEL_ID)
            ->willReturn(true);

        $this->assertSame(
            $paymentMethod,
            $this->controller->getValidPaymentMethod($this->salesChannelContext, 'PaypalPaymentHandler')
        );
    }

    public function testReturnsNullWhenPaymentMethodIsDisabled(): void
    {
        $this->givenPaymentMethod(['buckaroo_key' => 'paypal']);
        $this->settingsService->method('getEnabled')
            ->with('paypal', self::SALES_CHANNEL_ID)
            ->willReturn(false);

        $this->assertNull(
            $this->controller->getValidPaymentMethod($this->salesChannelContext, 'PaypalPaymentHandler')
        );
    }

    public function testReturnsNullWhenPaymentMethodHasNoBuckarooKey(): void
    {
        $this->givenPaymentMethod([]);
        $this->settingsService->expects($this->never())->method('getEnabled');

        $this->assertNull(
            $this->controller->getValidPaymentMethod($this->salesChannelContext, 'PaypalPaymentHandler')
        );
    }

    public function testReturnsNullWhenPaymentMethodIsNotFound(): void
    {
        $this->paymentMethodRepository->method('search')->willReturn($this->searchResult(null));
        $this->settingsService->expects($this->never())->method('getEnabled');

        $this->assertNull(
            $this->controller->getValidPaymentMethod($this->salesChannelContext, 'PaypalPaymentHandler')
        );
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function givenPaymentMethod(array $customFields): PaymentMethodEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('11111111111111111111111111111111');
        $paymentMethod->setTranslated(['customFields' => $customFields]);

        $this->paymentMethodRepository->method('search')->willReturn($this->searchResult($paymentMethod));

        return $paymentMethod;
    }

    private function searchResult(?PaymentMethodEntity $paymentMethod): EntitySearchResult
    {
        $collection = new PaymentMethodCollection($paymentMethod === null ? [] : [$paymentMethod]);

        return new EntitySearchResult(
            'payment_method',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext()
        );
    }
}
