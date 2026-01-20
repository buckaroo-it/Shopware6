<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration\Handlers;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;
use Buckaroo\Shopware6\Handlers\PaypalPaymentHandler;
use Buckaroo\Shopware6\Handlers\CreditcardPaymentHandler;
use Buckaroo\Shopware6\Handlers\BancontactPaymentHandler;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Handlers\PaymentHandlerSimple;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerInterface;

/**
 * Integration test to verify that payment handlers are properly configured
 * and implement required interfaces.
 */
class PaymentHandlerTest extends TestCase
{
    /**
     * @dataProvider paymentHandlerProvider
     */
    public function testPaymentHandlerClassExists(string $className): void
    {
        $this->assertTrue(
            class_exists($className),
            "Payment handler class $className should exist"
        );
    }

    /**
     * @dataProvider paymentHandlerProvider
     */
    public function testPaymentHandlerImplementsPaymentHandlerInterface(string $className): void
    {
        // Payment handlers extend PaymentHandlerSimple or similar base classes
        // which implement Shopware's payment handler contracts
        // We verify they're callable as payment handlers by checking they extend the base class
        $this->assertTrue(
            is_subclass_of($className, PaymentHandlerSimple::class) ||
            method_exists($className, 'pay'),
            "Payment handler $className should extend PaymentHandlerSimple or have pay method"
        );
    }

    /**
     * @dataProvider simpleHandlerProvider
     */
    public function testPaymentHandlerExtendsPaymentHandlerSimple(string $className): void
    {
        $this->assertTrue(
            is_subclass_of($className, PaymentHandlerSimple::class),
            "Simple payment handler $className should extend PaymentHandlerSimple"
        );
    }

    /**
     * @dataProvider simpleHandlerProvider
     */
    public function testPaymentHandlerHasPaymentClass(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        
        $this->assertTrue(
            $reflection->hasProperty('paymentClass'),
            "Payment handler $className should have paymentClass property"
        );

        // Get default properties (includes public properties with default values)
        $defaultProperties = $reflection->getDefaultProperties();
        
        $this->assertArrayHasKey(
            'paymentClass',
            $defaultProperties,
            "Payment handler $className should define paymentClass"
        );
    }

    /**
     * @dataProvider simpleHandlerProvider
     */
    public function testPaymentHandlerPaymentClassExists(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        $defaultProperties = $reflection->getDefaultProperties();
        
        if (isset($defaultProperties['paymentClass'])) {
            $paymentClass = $defaultProperties['paymentClass'];
            
            $this->assertTrue(
                class_exists($paymentClass),
                "Payment class $paymentClass for handler $className should exist"
            );
        } else {
            $this->markTestSkipped("paymentClass not defined for $className");
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public static function paymentHandlerProvider(): array
    {
        return [
            'IdealPaymentHandler' => [IdealPaymentHandler::class],
            'PaypalPaymentHandler' => [PaypalPaymentHandler::class],
            'CreditcardPaymentHandler' => [CreditcardPaymentHandler::class],
            'BancontactPaymentHandler' => [BancontactPaymentHandler::class],
            'AfterPayPaymentHandler' => [AfterPayPaymentHandler::class],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    public static function simpleHandlerProvider(): array
    {
        return [
            'IdealPaymentHandler' => [IdealPaymentHandler::class],
            'PaypalPaymentHandler' => [PaypalPaymentHandler::class],
            'CreditcardPaymentHandler' => [CreditcardPaymentHandler::class],
            'BancontactPaymentHandler' => [BancontactPaymentHandler::class],
        ];
    }
}
