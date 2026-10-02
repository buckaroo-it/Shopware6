<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Acl\AclAnnotationValidator;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\ShopwareHttpException;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Shopware only enforces ACL on a route through its `_acl` default, so every Buckaroo
 * Admin API action route must declare the privileges it needs.
 */
class AdminApiRouteAclTest extends TestCase
{
    private const EXPECTED_ACL = [
        'api.action.buckaroo.refund' => ['order_refund.editor'],
        'api.action.buckaroo.capture' => ['order.editor'],
        'api.action.buckaroo.paylink' => ['order.editor'],
        'api.action.buckaroo.klarna_mor' => ['order.editor'],
        'api.action.buckaroo.version' => ['system_config:read'],
        'api.action.buckaroo.tax' => ['tax:read'],
        'api.action.buckaroo.support.version' => ['order.viewer'],
        'api.action.buckaroo.support.apitest' => [
            'system_config:update',
            'system_config:create',
            'system_config:delete',
        ],
        'api.action.buckaroo.in3.logos' => ['payment_method:read'],
    ];

    public function testEveryAdminApiRouteDeclaresAcl(): void
    {
        $routes = $this->adminApiRoutes();

        $this->assertNotEmpty($routes);
        foreach ($routes as $name => $defaults) {
            $this->assertNotEmpty(
                $defaults[PlatformRequest::ATTRIBUTE_ACL] ?? null,
                "Admin API route $name must declare a non-empty _acl"
            );
        }
    }

    public function testAdminApiRoutesRequireExpectedPrivileges(): void
    {
        $actual = array_map(
            fn (array $defaults) => $defaults[PlatformRequest::ATTRIBUTE_ACL] ?? null,
            $this->adminApiRoutes()
        );

        ksort($actual);
        $expected = self::EXPECTED_ACL;
        ksort($expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * @dataProvider routeProvider
     */
    public function testIntegrationWithoutPrivilegeIsRejected(string $routeName): void
    {
        $source = new AdminApiSource(null, 'integration-id');
        $source->setPermissions(['product:read']);

        try {
            $this->validate(self::EXPECTED_ACL[$routeName], $source);
            $this->fail("Route $routeName must reject an integration without its privileges");
        } catch (ShopwareHttpException $e) {
            $this->assertSame(Response::HTTP_FORBIDDEN, $e->getStatusCode());
        }
    }

    /**
     * @dataProvider routeProvider
     */
    public function testIntegrationWithPrivilegeIsAllowed(string $routeName): void
    {
        $source = new AdminApiSource(null, 'integration-id');
        $source->setPermissions(self::EXPECTED_ACL[$routeName]);

        $this->validate(self::EXPECTED_ACL[$routeName], $source);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeProvider(): iterable
    {
        foreach (array_keys(self::EXPECTED_ACL) as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @param array<string> $acl
     */
    private function validate(array $acl, AdminApiSource $source): void
    {
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ACL, $acl);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, new Context($source));

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn () => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        (new AclAnnotationValidator($this->createMock(Connection::class)))->validate($event);
    }

    /**
     * Route defaults of every Admin API route declared by the plugin's controllers, by route name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function adminApiRoutes(): array
    {
        $routes = [];
        foreach (glob(__DIR__ . '/../../../../src/Storefront/Controller/*Controller.php') ?: [] as $file) {
            $class = 'Buckaroo\\Shopware6\\Storefront\\Controller\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    /** @var Route $route */
                    $route = $attribute->newInstance();
                    if (!str_starts_with((string) $route->getPath(), '/api/')) {
                        continue;
                    }
                    $routes[(string) $route->getName()] = $route->getDefaults();
                }
            }
        }

        return $routes;
    }
}
