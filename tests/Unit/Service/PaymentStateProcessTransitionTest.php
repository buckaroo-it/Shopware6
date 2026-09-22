<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Buckaroo\Shopware6\Service\PaymentStateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A pending Buckaroo payment (status 791) has to move the order transaction into the
 * "in progress" state, but only when the state machine actually offers that transition.
 *
 * Shopware renamed that transition from `do_pay` to `process` in 6.7
 * (Migration1742302302RenamePaidTransitionActions), so the name the state machine reports
 * differs per supported version. Matching only one of the two silently skips the state
 * change on the versions using the other name, which is what these tests pin down.
 */
class PaymentStateProcessTransitionTest extends TestCase
{
    private const TRANSACTION_ID = '0191d6cb27ee7f2ea0e1b3c4a5d6e7f8';

    /** @var OrderTransactionStateHandler&MockObject */
    private OrderTransactionStateHandler $transactionStateHandler;

    /** @var StateMachineRegistry&MockObject */
    private StateMachineRegistry $stateMachineRegistry;

    private PaymentStateService $paymentStateService;

    protected function setUp(): void
    {
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->stateMachineRegistry    = $this->createMock(StateMachineRegistry::class);

        $this->paymentStateService = new PaymentStateService(
            $this->transactionStateHandler,
            $this->stateMachineRegistry,
            $this->createMock(TranslatorInterface::class),
            $this->createMock(AccountService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * `do_pay` is the transition name on 6.5 and 6.6.
     */
    public function testAPendingPaymentIsProcessedWhenTheStateMachineOffersDoPay(): void
    {
        $this->givenAvailableTransitions(['cancel', 'do_pay', 'fail']);

        $this->transactionStateHandler
            ->expects($this->once())
            ->method('process')
            ->with(self::TRANSACTION_ID);

        $this->finalizePendingPayment();
    }

    /**
     * `process` is the transition name from 6.7 onwards. Before this was handled, the
     * pending payment was left in its original state on every 6.7 installation.
     */
    public function testAPendingPaymentIsProcessedWhenTheStateMachineOffersProcess(): void
    {
        $this->givenAvailableTransitions(['cancel', 'process', 'fail']);

        $this->transactionStateHandler
            ->expects($this->once())
            ->method('process')
            ->with(self::TRANSACTION_ID);

        $this->finalizePendingPayment();
    }

    /**
     * Neither name offered means the transaction is already past that point, so the
     * transition must not be forced.
     */
    public function testAPendingPaymentIsLeftAloneWhenNeitherTransitionIsOffered(): void
    {
        $this->givenAvailableTransitions(['refund', 'cancel']);

        $this->transactionStateHandler
            ->expects($this->never())
            ->method('process');

        $this->finalizePendingPayment();
    }

    /**
     * @param array<string> $actionNames
     */
    private function givenAvailableTransitions(array $actionNames): void
    {
        $transitions = [];

        foreach ($actionNames as $actionName) {
            $transition = new StateMachineTransitionEntity();
            $transition->setActionName($actionName);
            $transitions[] = $transition;
        }

        $this->stateMachineRegistry
            ->method('getAvailableTransitions')
            ->willReturn($transitions);
    }

    private function finalizePendingPayment(): void
    {
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')->willReturn(self::TRANSACTION_ID);

        $request = new Request([], [
            'brq_statuscode' => ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING,
        ]);

        $this->paymentStateService->finalizePayment(
            $transaction,
            $request,
            Context::createDefaultContext()
        );
    }
}
