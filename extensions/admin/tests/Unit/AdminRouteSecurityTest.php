<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Admin\AdminExtension;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuditMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuthMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCsrfMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminSchemaMiddleware;
use Pulsar\Routing\Router;

/**
 * Guards the security regression where every admin middleware was bound in
 * the container but never attached to a route, leaving the panel serving
 * unauthenticated CRUD and schema DDL. Each admin route must carry the full
 * security stack; schema routes must additionally enforce the schema guard.
 */
#[CoversClass(AdminExtension::class)]
final class AdminRouteSecurityTest extends TestCase
{
    /** @var list<class-string> */
    private const array SECURITY_STACK = [
        AdminAuditMiddleware::class,
        AdminCspMiddleware::class,
        AdminAccessMiddleware::class,
        AdminRateLimitMiddleware::class,
        AdminAuthMiddleware::class,
        AdminCsrfMiddleware::class,
    ];

    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();

        $config = AdminConfig::fromArray([
            'enabled' => true,
            'schema' => ['enabled' => true],
        ]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($config);
        $container->method('has')->willReturn(false);

        new AdminExtension()->boot($container, $this->router);
    }

    #[Test]
    public function everyAdminRouteCarriesTheFullSecurityStack(): void
    {
        self::assertNotSame([], $this->router->routes, 'admin routes must be registered');

        foreach ($this->router->routes as $route) {
            foreach (self::SECURITY_STACK as $middleware) {
                self::assertContains(
                    $middleware,
                    $route->middleware,
                    "Route '{$route->name}' ({$route->path}) is missing $middleware",
                );
            }
        }
    }

    #[Test]
    public function theSecurityStackIsAppliedInTheExpectedOrder(): void
    {
        $dashboard = $this->router->namedRoutes['admin.dashboard'] ?? null;

        self::assertNotNull($dashboard);
        self::assertSame(self::SECURITY_STACK, $dashboard->middleware);
    }

    #[Test]
    public function schemaRoutesAdditionallyEnforceTheSchemaGuard(): void
    {
        $schemaRoute = $this->router->namedRoutes['admin.api.schema.create'] ?? null;

        self::assertNotNull($schemaRoute, 'schema routes must be registered when schema is enabled');
        self::assertContains(AdminSchemaMiddleware::class, $schemaRoute->middleware);
    }

    #[Test]
    public function nonSchemaRoutesDoNotCarryTheSchemaGuard(): void
    {
        $dashboard = $this->router->namedRoutes['admin.dashboard'] ?? null;

        self::assertNotNull($dashboard);
        self::assertNotContains(AdminSchemaMiddleware::class, $dashboard->middleware);
    }
}
