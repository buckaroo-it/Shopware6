<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\CancelPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\PaymentStateService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\UrlService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * AfterPay, Billink and In3 fall back to the address-level VAT ID for the chamber of
 * commerce number when the checkout form leaves it blank.
 *
 * OrderAddressEntity::getVatId() is deprecated in 6.7.6.0 and removed in 6.8, so the value
 * is read through the entity's has()/get() accessors instead. These tests pin that the
 * fallback still returns the stored value on the versions that have the field, and that a
 * missing or non-string value degrades to null rather than throwing - the shape the code
 * hits once Shopware drops the property.
 */
class AsyncPaymentServiceVatIdTest extends TestCase
{
    private AsyncPaymentService $asyncPaymentService;

    protected function setUp(): void
    {
        $this->asyncPaymentService = new AsyncPaymentService(
            $this->createMock(SettingsService::class),
            $this->createMock(UrlService::class),
            $this->createMock(StateTransitionService::class),
            $this->createMock(ClientService::class),
            $this->createMock(CheckoutHelper::class),
            $this->createMock(TransactionService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FormatRequestParamService::class),
            $this->createMock(PaymentStateService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(CancelPaymentService::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(SalesChannelContextServiceInterface::class)
        );
    }

    public function testAStoredVatIdIsReturned(): void
    {
        $address = new OrderAddressEntity();
        $address->assign(['vatId' => 'NL123456789B01']);

        $this->assertSame(
            'NL123456789B01',
            $this->asyncPaymentService->getAddressVatId($address)
        );
    }

    public function testAnUnsetVatIdYieldsNull(): void
    {
        $this->assertNull(
            $this->asyncPaymentService->getAddressVatId(new OrderAddressEntity())
        );
    }

    /**
     * The property is typed ?string on the entity, but it is populated from the DAL rather
     * than through the setter, so a non-string must not leak into the payment payload.
     */
    public function testANonStringVatIdYieldsNull(): void
    {
        $address = new OrderAddressEntity();
        $address->assign(['vatId' => 12345]);

        $this->assertNull($this->asyncPaymentService->getAddressVatId($address));
    }
}
