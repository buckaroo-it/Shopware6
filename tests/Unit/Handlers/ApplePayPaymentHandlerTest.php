<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Handlers\ApplePayPaymentHandler;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Tests\Unit\TestHelper\PhpNoticeGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ApplePayPaymentHandlerTest extends TestCase
{
    use PhpNoticeGuard;

    public function testBillingContactNameIsUsed(): void
    {
        $payload = $this->getMethodPayload([
            'billingContact' => ['givenName' => 'Jane', 'familyName' => 'Doe'],
            'shippingContact' => ['givenName' => 'John', 'familyName' => 'Roe'],
        ]);

        $this->assertSame('Jane Doe', $payload['customerCardName']);
    }

    public function testShippingContactNameIsUsedWithoutBillingContact(): void
    {
        $payload = $this->getMethodPayload([
            'shippingContact' => ['givenName' => 'John', 'familyName' => 'Roe'],
        ]);

        $this->assertSame('John Roe', $payload['customerCardName']);
    }

    /**
     * @dataProvider unusableNamePartProvider
     */
    public function testUnusableGivenNameFallsBackToOrderCustomer(mixed $givenName): void
    {
        $payload = $this->getMethodPayload([
            'billingContact' => ['givenName' => $givenName, 'familyName' => 'Doe'],
        ]);

        $this->assertSame('Order Customer', $payload['customerCardName']);
    }

    /**
     * @dataProvider unusableNamePartProvider
     */
    public function testUnusableFamilyNameFallsBackToOrderCustomer(mixed $familyName): void
    {
        $payload = $this->getMethodPayload([
            'billingContact' => ['givenName' => 'Jane', 'familyName' => $familyName],
        ]);

        $this->assertSame('Order Customer', $payload['customerCardName']);
    }

    /**
     * @dataProvider unusableNamePartProvider
     */
    public function testUnusableBillingNameFallsBackToShippingContact(mixed $namePart): void
    {
        $payload = $this->getMethodPayload([
            'billingContact' => ['givenName' => $namePart, 'familyName' => $namePart],
            'shippingContact' => ['givenName' => 'John', 'familyName' => 'Roe'],
        ]);

        $this->assertSame('John Roe', $payload['customerCardName']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableNamePartProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'array' => [['Jane']],
            'object' => [['first' => 'Jane']],
            'integer' => [123],
            'float' => [1.5],
            'boolean' => [true],
        ];
    }

    /**
     * @dataProvider unusableContactProvider
     */
    public function testUnusableContactFallsBackToOrderCustomer(mixed $contact): void
    {
        $payload = $this->getMethodPayload(['billingContact' => $contact]);

        $this->assertSame('Order Customer', $payload['customerCardName']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableContactProvider(): array
    {
        return [
            'string' => ['Jane Doe'],
            'list' => [['Jane', 'Doe']],
            'null' => [null],
            'empty object' => [new \stdClass()],
        ];
    }

    public function testMissingNamesWithoutOrderCustomerGiveEmptyName(): void
    {
        $payload = $this->getMethodPayload(
            ['billingContact' => ['givenName' => ['Jane'], 'familyName' => 'Doe']],
            new OrderEntity()
        );

        $this->assertSame('', $payload['customerCardName']);
    }

    /**
     * @param array<string, mixed> $applePayInfo
     *
     * @return array<mixed>
     */
    private function getMethodPayload(array $applePayInfo, ?OrderEntity $order = null): array
    {
        $handler = new ApplePayPaymentHandler(
            $this->createMock(AsyncPaymentService::class),
            $this->createMock(FormatRequestParamService::class)
        );

        $applePayInfo['token'] = ['paymentData' => 'data'];
        $dataBag = new RequestDataBag(['applePayInfo' => json_encode($applePayInfo, JSON_THROW_ON_ERROR)]);

        return $this->withoutPhpNotices(
            fn () => $handler->getMethodPayload(
                $order ?? $this->createOrder(),
                $dataBag,
                $this->createMock(SalesChannelContext::class),
                'applepay'
            )
        );
    }

    private function createOrder(): OrderEntity
    {
        $customer = new OrderCustomerEntity();
        $customer->setFirstName('Order');
        $customer->setLastName('Customer');

        $order = new OrderEntity();
        $order->setOrderCustomer($customer);

        return $order;
    }
}
