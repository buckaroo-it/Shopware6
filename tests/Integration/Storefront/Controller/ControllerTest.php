<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Integration\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Storefront\Controller\PushController;
use Buckaroo\Shopware6\Storefront\Controller\CaptureController;
use Buckaroo\Shopware6\Storefront\Controller\RefundController;
use Buckaroo\Shopware6\Storefront\Controller\ApplePayController;
use Buckaroo\Shopware6\Storefront\Controller\PaypalExpressController;
use Shopware\Storefront\Controller\StorefrontController;

/**
 * Integration test to verify that controllers are properly configured
 * and extend required base classes.
 */
class ControllerTest extends TestCase
{
    /**
     * @dataProvider controllerProvider
     */
    public function testControllerClassExists(string $className): void
    {
        $this->assertTrue(
            class_exists($className),
            "Controller class $className should exist"
        );
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testControllerExtendsStorefrontController(string $className): void
    {
        $this->assertTrue(
            is_subclass_of($className, StorefrontController::class),
            "Controller $className should extend StorefrontController"
        );
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testControllerHasConstructor(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        
        $this->assertTrue(
            $reflection->hasMethod('__construct'),
            "Controller $className should have a constructor"
        );
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testControllerConstructorIsPublic(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        
        if ($reflection->hasMethod('__construct')) {
            $constructor = $reflection->getMethod('__construct');
            $this->assertTrue(
                $constructor->isPublic(),
                "Controller $className constructor should be public"
            );
        } else {
            $this->markTestSkipped("No constructor defined for $className");
        }
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testControllerHasMethods(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        // Filter out inherited methods from parent classes
        $ownMethods = array_filter($methods, function ($method) use ($className) {
            return $method->getDeclaringClass()->getName() === $className;
        });

        $this->assertNotEmpty(
            $ownMethods,
            "Controller $className should have at least one public method"
        );
    }

    public function testPushControllerHasAuthorizeRequestsConstant(): void
    {
        $reflection = new \ReflectionClass(PushController::class);
        
        $this->assertTrue(
            $reflection->hasConstant('AUTHORIZE_REQUESTS'),
            "PushController should have AUTHORIZE_REQUESTS constant"
        );

        $authorizeRequests = $reflection->getConstant('AUTHORIZE_REQUESTS');
        $this->assertIsArray($authorizeRequests);
        $this->assertNotEmpty($authorizeRequests);
    }

    public function testPushControllerAuthorizeRequestsContainsExpectedCodes(): void
    {
        $reflection = new \ReflectionClass(PushController::class);
        $authorizeRequests = $reflection->getConstant('AUTHORIZE_REQUESTS');

        // Verify some expected authorization codes
        $expectedCodes = ['I872', 'V054', 'I876'];
        
        foreach ($expectedCodes as $code) {
            $this->assertContains(
                $code,
                $authorizeRequests,
                "AUTHORIZE_REQUESTS should contain code $code"
            );
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public static function controllerProvider(): array
    {
        return [
            'PushController' => [PushController::class],
            'CaptureController' => [CaptureController::class],
            'RefundController' => [RefundController::class],
            'ApplePayController' => [ApplePayController::class],
            'PaypalExpressController' => [PaypalExpressController::class],
        ];
    }
}
