<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteCollisionReporter;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(RouteCollisionReporter::class)]
final class RouteCollisionReporterTest extends TestCase
{
    private function routerWithCollision(): Router
    {
        // Single-method routes so exactly one collision is recorded (a GET
        // route always also serves HEAD per the Route constructor's RFC 9110
        // normalization, which would yield two).
        $router = new Router();
        $router->add(new Route(methods: [Method::POST], path: '/booking', handler: ['ProjectController', 'form'], name: 'project.booking'));
        $router->add(new Route(methods: [Method::POST], path: '/booking', handler: ['ExtensionController', 'stub'], name: 'extension.booking'));

        return $router;
    }

    #[Test]
    public function warnsPerCollisionAndDoesNotThrowInProduction(): void
    {
        $router = $this->routerWithCollision();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        RouteCollisionReporter::report($router, $logger, false);
    }

    #[Test]
    public function failsClosedInDebugMode(): void
    {
        $router = $this->routerWithCollision();

        // A null logger is a valid boot state (logging not yet wired); the reporter
        // must still fail closed in debug mode.
        $this->expectException(RoutingException::class);
        RouteCollisionReporter::report($router, null, true);
    }

    #[Test]
    public function noOpWhenThereAreNoCollisions(): void
    {
        $router = new Router();
        $router->get('/only', ['Controller', 'index'], 'only');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        // Must not throw even in debug mode when there are no collisions.
        RouteCollisionReporter::report($router, $logger, true);
        self::assertSame([], $router->collisions);
    }
}
