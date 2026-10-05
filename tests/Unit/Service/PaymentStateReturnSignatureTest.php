<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Buckaroo\Shopware6\Service\PaymentStateService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Buckaroo status on the return request is only acted on when the return is signed.
 * Without a valid signature the payment state is left to the push.
 */
class PaymentStateReturnSignatureTest extends TestCase
{
    private const TRANSACTION_ID = '0191d6cb27ee7f2ea0e1b3c4a5d6e7f8';

    private const SALES_CHANNEL_ID = 'sales-channel-id';

    private const SECRET_KEY = 'test-secret-key';

    /** @var OrderTransactionStateHandler&MockObject */
    private OrderTransactionStateHandler $transactionStateHandler;

    /** @var StateMachineRegistry&MockObject */
    private StateMachineRegistry $stateMachineRegistry;

    private PaymentStateService $paymentStateService;

    protected function setUp(): void
    {
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->stateMachineRegistry    = $this->createMock(StateMachineRegistry::class);

        $transition = new StateMachineTransitionEntity();
        $transition->setActionName('process');
        $failTransition = new StateMachineTransitionEntity();
        $failTransition->setActionName('fail');
        $this->stateMachineRegistry->method('getAvailableTransitions')->willReturn([$transition, $failTransition]);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->with('secretKey', self::SALES_CHANNEL_ID)
            ->willReturn(self::SECRET_KEY);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->paymentStateService = new PaymentStateService(
            $this->transactionStateHandler,
            $this->stateMachineRegistry,
            $translator,
            $this->createMock(AccountService::class),
            $this->createMock(LoggerInterface::class),
            new SignatureValidationService($settingsService, $this->createMock(LoggerInterface::class))
        );
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testUnsignedReturnDoesNotChangeThePaymentState(string $statusCode): void
    {
        $this->transactionStateHandler->expects($this->never())->method('process');

        $this->finalize(new Request([], ['brq_statuscode' => $statusCode]));
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testReturnWithInvalidSignatureDoesNotChangeThePaymentState(string $statusCode): void
    {
        $this->transactionStateHandler->expects($this->never())->method('process');

        $this->finalize(new Request([], ['brq_statuscode' => $statusCode, 'brq_signature' => 'invalid']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusCodeProvider(): array
    {
        return [
            'pending' => [ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING],
            'failed' => [ResponseStatus::BUCKAROO_STATUSCODE_FAILED],
            'cancelled by user' => [ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER],
        ];
    }

    public function testUnsignedGroupTransactionCancelDoesNotCancel(): void
    {
        $this->finalize(new Request(['scenario' => 'Cancellation']));

        $this->addToAssertionCount(1);
    }

    public function testSignedPendingReturnIsProcessed(): void
    {
        $this->transactionStateHandler->expects($this->once())->method('process')->with(self::TRANSACTION_ID);

        $this->finalize(new Request([], $this->signed([
            'brq_statuscode' => ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING,
        ])));
    }

    /**
     * Buckaroo can also send the return fields in the query string.
     */
    public function testSignedFailedReturnInTheQueryStringFailsThePayment(): void
    {
        $this->expectException(PaymentException::class);

        $this->finalize(new Request($this->signed([
            'brq_statuscode' => ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
        ]) + ['_sw_payment_token' => 'token']));
    }

    public function testSignedCancelledReturnCancelsThePayment(): void
    {
        $this->expectException(PaymentException::class);

        $this->finalize(new Request([], $this->signed([
            'brq_statuscode' => ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER,
        ])));
    }

    /**
     * The plugin's own cancel URL still cancels the payment.
     */
    public function testPluginCancelUrlCancelsWithoutSignature(): void
    {
        $this->expectException(PaymentException::class);

        $this->finalize(new Request(['cancel' => '1']));
    }

    private function finalize(Request $request): void
    {
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')->willReturn(self::TRANSACTION_ID);

        $this->paymentStateService->finalizePayment(
            $transaction,
            $request,
            Context::createDefaultContext(new SalesChannelApiSource(self::SALES_CHANNEL_ID))
        );
    }

    /**
     * Sign the fields the way Buckaroo does.
     *
     * @param array<string, string> $data
     *
     * @return array<string, string>
     */
    private function signed(array $data): array
    {
        $signed = $data;
        uksort($signed, static fn ($a, $b): int => strcmp(strtolower((string) $a), strtolower((string) $b)));

        $signatureString = '';
        foreach ($signed as $key => $value) {
            $signatureString .= $key . '=' . html_entity_decode($value);
        }

        $data['brq_signature'] = sha1($signatureString . self::SECRET_KEY);

        return $data;
    }
}
