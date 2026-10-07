<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Extension\HealthStatus\HealthStatusExtension;
use Pulsar\Extension\HealthStatus\Server\Middleware\StatusAccessMiddleware;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use RuntimeException;

/**
 * Verifies that all health-status routes include the StatusAccessMiddleware.
 */
#[CoversClass(HealthStatusExtension::class)]
final class HealthStatusRoutesMiddlewareTest extends TestCase
{
    #[Test]
    public function allRoutesHaveStatusAccessMiddleware(): void
    {
        /** @var list<Route> $registeredRoutes */
        $registeredRoutes = [];

        $router = $this->createStub(RouterInterface::class);
        $router->method('add')->willReturnCallback(
            static function (Route $route) use (&$registeredRoutes, $router): RouterInterface {
                $registeredRoutes[] = $route;

                return $router;
            },
        );

        $config = new HealthStatusConfig(enabled: true);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => match ($id) {
                HealthStatusConfig::class => true,
                default => false,
            },
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                HealthStatusConfig::class => $config,
                default => throw new RuntimeException("Unexpected get: $id"),
            },
        );

        $extension = new HealthStatusExtension();
        $extension->boot($container, $router);

        self::assertNotEmpty($registeredRoutes, 'Expected routes to be registered');
        self::assertCount(6, $registeredRoutes, 'Expected 6 health-status routes');

        foreach ($registeredRoutes as $route) {
            self::assertContains(
                StatusAccessMiddleware::class,
                $route->middleware,
                "Route '{$route->name}' must include StatusAccessMiddleware",
            );
        }
    }
}
