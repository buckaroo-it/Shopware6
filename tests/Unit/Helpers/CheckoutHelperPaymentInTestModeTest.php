<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Helpers;

use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The order records whether it was paid on the Buckaroo test environment, written only when
 * that changes and without touching the other custom fields of the order.
 */
class CheckoutHelperPaymentInTestModeTest extends TestCase
{
    private const ORDER_ID = 'order-id';

    /** @var EntityRepository&MockObject */
    private EntityRepository $orderRepository;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
    }

    public function testTestPaymentMarksAnOrderWithoutMarker(): void
    {
        $this->expectWrite(['otherField' => 'kept', CheckoutHelper::PAYMENT_IN_TEST_MODE => true]);

        $this->helper(['otherField' => 'kept'])
            ->setPaymentInTestMode($this->order(['otherField' => 'kept']), true, Context::createDefaultContext());
    }

    public function testLivePaymentResetsAMarkerOfAnEarlierTestAttempt(): void
    {
        $stored = ['otherField' => 'kept', CheckoutHelper::PAYMENT_IN_TEST_MODE => true];
        $this->expectWrite(['otherField' => 'kept', CheckoutHelper::PAYMENT_IN_TEST_MODE => false]);

        $this->helper($stored)->setPaymentInTestMode($this->order($stored), false, Context::createDefaultContext());
    }

    public function testLivePaymentDoesNotWriteToAnOrderWithoutMarker(): void
    {
        $this->orderRepository->expects($this->never())->method('update');

        $this->helper(['otherField' => 'kept'])
            ->setPaymentInTestMode($this->order(['otherField' => 'kept']), false, Context::createDefaultContext());
    }

    public function testLivePaymentDoesNotWriteToAnOrderWithoutCustomFields(): void
    {
        $this->orderRepository->expects($this->never())->method('update');

        $this->helper(null)->setPaymentInTestMode($this->order(null), false, Context::createDefaultContext());
    }

    public function testUnchangedMarkerIsNotWrittenAgain(): void
    {
        $stored = [CheckoutHelper::PAYMENT_IN_TEST_MODE => true];
        $this->orderRepository->expects($this->never())->method('update');

        $this->helper($stored)->setPaymentInTestMode($this->order($stored), true, Context::createDefaultContext());
    }

    /**
     * The other custom fields are taken from the order as stored, not from the entity the
     * caller passes in, so fields written in between are kept.
     */
    public function testOtherCustomFieldsAreTakenFromTheStoredOrder(): void
    {
        $this->expectWrite([
            'otherField' => 'kept',
            'newerField' => 'kept',
            CheckoutHelper::PAYMENT_IN_TEST_MODE => true,
        ]);

        $this->helper(['otherField' => 'kept', 'newerField' => 'kept'])
            ->setPaymentInTestMode($this->order(['otherField' => 'kept']), true, Context::createDefaultContext());
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function expectWrite(array $customFields): void
    {
        $this->orderRepository->expects($this->once())
            ->method('update')
            ->with([['id' => self::ORDER_ID, 'customFields' => $customFields]], $this->isInstanceOf(Context::class));
    }

    /**
     * @param array<string, mixed>|null $storedCustomFields custom fields of the order as stored
     */
    private function helper(?array $storedCustomFields): CheckoutHelper
    {
        /** @var CheckoutHelper&MockObject $helper */
        $helper = $this->getMockBuilder(CheckoutHelper::class)
            ->setConstructorArgs([
                $this->createMock(SettingsService::class),
                $this->orderRepository,
                $this->createMock(BuckarooTransactionEntityRepository::class),
                new RequestStack(),
            ])
            ->onlyMethods(['getOrderById'])
            ->getMock();
        $helper->method('getOrderById')->willReturn($this->order($storedCustomFields));

        return $helper;
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function order(?array $customFields): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setCustomFields($customFields);

        return $order;
    }
}
