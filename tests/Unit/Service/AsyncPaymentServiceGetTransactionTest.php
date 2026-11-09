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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The delivery address of an order is the "shippingOrderAddress" association; there is no
 * "shippingAddress" association on order_delivery. With the wrong name the DAL ignored the
 * association (logging "Criteria association "shippingAddress" could not be resolved"), so on
 * Shopware 6.6/6.7, where the association is not autoloaded, getShippingAddress() fell back to
 * the billing address for orders loaded through getTransaction().
 */
class AsyncPaymentServiceGetTransactionTest extends TestCase
{
    public function testTheDeliveryShippingAddressAndItsCountryAreLoaded(): void
    {
        $criteria = $this->captureCriteria();

        $deliveries = $criteria->getAssociation('order.deliveries');
        $this->assertTrue($deliveries->hasAssociation('shippingOrderAddress'));
        $this->assertTrue($deliveries->getAssociation('shippingOrderAddress')->hasAssociation('country'));
        $this->assertFalse($deliveries->hasAssociation('shippingAddress'));
    }

    private function captureCriteria(): Criteria
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return new EntitySearchResult(
                    'order_transaction',
                    0,
                    new OrderTransactionCollection(),
                    null,
                    $criteria,
                    $context
                );
            }
        );

        $service = new AsyncPaymentService(
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
            $repository,
            $this->createMock(SalesChannelContextServiceInterface::class)
        );

        $service->getTransaction('0191d6cb27ee7f2ea0e1b3c4a5d6e7f8', Context::createDefaultContext());

        $this->assertInstanceOf(Criteria::class, $captured);

        return $captured;
    }
}
