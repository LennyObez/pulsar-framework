<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Boot\RouteCollisionReporter;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(RouteCollisionReporter::class)]
final class RouteCollisionReporterTest extends TestCase
{
    private function routerWithCollision(): Router
    {
        // Single-method routes so exactly one collision is recorded (the GET-only
        // helper would register GET and HEAD, yielding two).
        $router = new Router();
        $router->add(new Route(methods: [Method::GET], path: '/booking', handler: ['ProjectController', 'form'], name: 'project.booking'));
        $router->add(new Route(methods: [Method::GET], path: '/booking', handler: ['ExtensionController', 'stub'], name: 'extension.booking'));

        return $router;
    }

    #[Test]
    public function warnsPerCollisionAndDoesNotThrowInProduction(): void
    {
        $router = $this->routerWithCollision();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($logger);

        RouteCollisionReporter::report($router, $container, false);
    }

    #[Test]
    public function failsClosedInDebugMode(): void
    {
        $router = $this->routerWithCollision();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $this->expectException(RoutingException::class);
        RouteCollisionReporter::report($router, $container, true);
    }

    #[Test]
    public function noOpWhenThereAreNoCollisions(): void
    {
        $router = new Router();
        $router->get('/only', ['Controller', 'index'], 'only');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('has');
        $container->expects(self::never())->method('get');

        // Must not throw even in debug mode when there are no collisions.
        RouteCollisionReporter::report($router, $container, true);
        self::assertSame([], $router->collisions);
    }
}
