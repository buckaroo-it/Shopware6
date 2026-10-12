<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Entity\IdealQrOrder\IdealQrOrderRepository;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The push body carries the signature, customer personal data (iDEAL Fast Checkout) and
 * ADD_ parameters such as the context token. Only a fixed set of non-sensitive fields of
 * it may be logged.
 */
class PushControllerLoggingTest extends TestCase
{
    public function testOnlyNonSensitivePushFieldsAreLogged(): void
    {
        $postData = [
            'brq_transactions' => 'TRANSACTION-KEY',
            'brq_statuscode' => '190',
            'brq_invoicenumber' => 'INV-001',
            'brq_ordernumber' => '10001',
            'brq_transaction_method' => 'ideal',
            'brq_primary_service' => 'ideal',
            'brq_transaction_type' => 'C021',
            'brq_mutationtype' => 'Processing',
            'brq_amount' => '10.00',
            'brq_signature' => 'secret-signature',
            'ADD_orderId' => 'order-id',
            'ADD_sw-context-token' => 'secret-context-token',
            'brq_SERVICE_ideal_TransactionFlow' => 'Fast_Checkout',
            'brq_SERVICE_ideal_ContactDetailsEmail' => 'consumer@example.com',
            'brq_SERVICE_ideal_ContactDetailsFirstName' => 'Consumer',
            'brq_SERVICE_ideal_InvoiceAddressStreet' => 'Consumer Street 1',
        ];

        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                static function (string $message, array $context = []) use (&$logged): void {
                    $logged[] = ['message' => $message, 'context' => $context];
                }
            );
        }

        $signatureValidationService = $this->createMock(SignatureValidationService::class);
        $signatureValidationService->method('validateSignature')->willReturn(false);

        $controller = new PushController(
            $signatureValidationService,
            $this->createMock(TransactionService::class),
            $this->createMock(StateTransitionService::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(CheckoutHelper::class),
            $logger,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(IdealQrOrderRepository::class),
            $this->createMock(OrderService::class),
            $this->createMock(CustomerService::class)
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        $controller->pushBuckaroo(new Request([], $postData), $this->createMock(SalesChannelContext::class));

        $this->assertNotEmpty($logged);
        $this->assertSame(
            [
                'brq_transactions' => 'TRANSACTION-KEY',
                'brq_statuscode' => '190',
                'brq_invoicenumber' => 'INV-001',
                'brq_ordernumber' => '10001',
                'brq_transaction_method' => 'ideal',
                'brq_primary_service' => 'ideal',
                'brq_transaction_type' => 'C021',
                'brq_mutationtype' => 'Processing',
            ],
            $logged[0]['context'],
            'the received push must be logged with the non-sensitive fields only'
        );

        $allLogged = (string)json_encode($logged);
        foreach (['secret-signature', 'secret-context-token', 'consumer@example.com', 'Consumer Street 1'] as $value) {
            $this->assertStringNotContainsString($value, $allLogged);
        }
    }
}
