<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\CustomerAddressService;
use Buckaroo\Shopware6\Service\CustomerService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Customers created by express checkouts follow Shopware's "Bind customers to Sales Channel" rule.
 */
class CustomerServiceBoundSalesChannelTest extends TestCase
{
    private const SALES_CHANNEL_ID = '019e4980f2bd718ebb40db6f6bcd7efe';
    private const EMAIL = 'buyer@example.com';

    public function testCustomerIsBoundWhenTheSettingIsEnabled(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('searchIds');

        $this->assertSame(self::SALES_CHANNEL_ID, $this->boundSalesChannelId(true, $repository, self::EMAIL));
    }

    public function testCustomerIsBoundWhenTheEmailAlreadyHasABoundAccount(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('searchIds')
            ->willReturnCallback(fn (Criteria $criteria, Context $context) => $this->idResult(1, $criteria, $context));

        $this->assertSame(self::SALES_CHANNEL_ID, $this->boundSalesChannelId(false, $repository, self::EMAIL));
    }

    public function testCustomerIsNotBoundOtherwise(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')
            ->willReturnCallback(fn (Criteria $criteria, Context $context) => $this->idResult(0, $criteria, $context));

        $this->assertNull($this->boundSalesChannelId(false, $repository, self::EMAIL));
    }

    public function testCustomerWithoutEmailIsNotBoundWhenTheSettingIsDisabled(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('searchIds');

        $this->assertNull($this->boundSalesChannelId(false, $repository, ''));
    }

    private function boundSalesChannelId(
        bool $settingEnabled,
        EntityRepository $customerRepository,
        string $email
    ): ?string {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('getBool')
            ->with('core.systemWideLoginRegistration.isCustomerBoundToSalesChannel')
            ->willReturn($settingEnabled);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $service = new CustomerService(
            $this->createMock(CustomerAddressService::class),
            $customerRepository,
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(SalesChannelContextRestorer::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(SalesChannelContextPersister::class),
            $systemConfigService
        );
        $service->setSaleChannelContext($salesChannelContext);

        $method = new \ReflectionMethod(CustomerService::class, 'getBoundSalesChannelId');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke($service, $email);

        return $result;
    }

    private function idResult(int $total, Criteria $criteria, Context $context): IdSearchResult
    {
        $id = '0190000000000000000000000000000a';
        $ids = $total > 0 ? [$id => ['primaryKey' => $id, 'data' => []]] : [];

        return new IdSearchResult($total, $ids, $criteria, $context);
    }
}
