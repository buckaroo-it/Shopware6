<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Storefront\Controller\PushController;

/**
 * A failure push (491 validation failure, technical error, rejected, cancelled) must
 * never move a transaction that is already settled. Regression guard for the Klarna KP
 * incident where a 491 "Pay on reservation ... reservation has status FullyCaptured"
 * push arriving after a refund transitioned the payment to cancelled.
 */
class PushControllerSettledStatesTest extends TestCase
{
    public function testRefundedStatesAreTreatedAsSettled(): void
    {
        $this->assertContains('refunded', PushController::SETTLED_PAYMENT_STATES);
        $this->assertContains('partial_refunded', PushController::SETTLED_PAYMENT_STATES);
    }

    public function testPaidStatesRemainSettled(): void
    {
        $this->assertContains('paid', PushController::SETTLED_PAYMENT_STATES);
        $this->assertContains('pay_partially', PushController::SETTLED_PAYMENT_STATES);
    }

    /**
     * An authorization is not settled: a failure push against it is plausibly a real
     * payment failure and must still be able to fail/cancel the transaction.
     */
    public function testAuthorizedIsNotTreatedAsSettled(): void
    {
        $this->assertNotContains('authorize', PushController::SETTLED_PAYMENT_STATES);
        $this->assertNotContains('authorized', PushController::SETTLED_PAYMENT_STATES);
    }
}
