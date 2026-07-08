<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteCollision;
use Pulsar\Routing\Router;

/**
 * Route registration is first-registered-wins (framework > project > extension).
 * A later route claiming an already-registered (method, path, host) key is
 * recorded as a collision and excluded from the match tables rather than
 * silently overriding the earlier route. These tests lock in that contract,
 * which closes the "extension silently shadows a project route" bug.
 *
 * Single-method routes are built explicitly via {@see Route} so collision counts
 * are deterministic; the convenience `get()` helper registers both GET and HEAD,
 * which legitimately produces one collision per method.
 */
#[CoversClass(Router::class)]
#[CoversClass(RouteCollision::class)]
final class RouterCollisionTest extends TestCase
{
    private static function getRoute(string $path, mixed $handler, string $name, ?string $host = null): Route
    {
        return new Route(methods: [Method::GET], path: $path, handler: $handler, name: $name, host: $host);
    }

    #[Test]
    public function firstRegisteredStaticRouteWinsAndCollisionIsRecorded(): void
    {
        // Project route registers first, extension route second (real boot order).
        $router = new Router();
        $router->add(self::getRoute('/booking', ['ProjectController', 'form'], 'project.booking'));
        $router->add(self::getRoute('/booking', ['ExtensionController', 'stub'], 'extension.booking'));

        // The project (first-registered) route wins the match, not the extension.
        self::assertSame('project.booking', $router->match(Method::GET, '/booking')->getName());

        self::assertCount(1, $router->collisions);
        $collision = $router->collisions[0];
        self::assertInstanceOf(RouteCollision::class, $collision);
        self::assertSame('GET', $collision->method);
        self::assertSame('/booking', $collision->path);
        self::assertSame('project.booking', $collision->winner->name);
        self::assertSame('extension.booking', $collision->shadowed->name);
    }

    #[Test]
    public function firstRegisteredDynamicRouteWinsAndCollisionIsRecorded(): void
    {
        $router = new Router();
        $router->add(self::getRoute('/users/{id}', ['ProjectController', 'show'], 'project.user'));
        $router->add(self::getRoute('/users/{id}', ['ExtensionController', 'show'], 'extension.user'));

        self::assertSame('project.user', $router->match(Method::GET, '/users/42')->getName());
        self::assertCount(1, $router->collisions);
    }

    #[Test]
    public function sameMethodAndPathButDifferentHostDoesNotCollide(): void
    {
        $router = new Router();
        $router->add(self::getRoute('/dashboard', ['A', 'x'], 'a', 'a.example.com'));
        $router->add(self::getRoute('/dashboard', ['B', 'x'], 'b', 'b.example.com'));

        self::assertSame([], $router->collisions);
    }

    #[Test]
    public function differentMethodsOnSamePathDoNotCollide(): void
    {
        $router = new Router();
        $router->add(new Route(methods: [Method::GET], path: '/resource', handler: ['A', 'index'], name: 'a'));
        $router->add(new Route(methods: [Method::POST], path: '/resource', handler: ['B', 'store'], name: 'b'));

        self::assertSame([], $router->collisions);
    }

    #[Test]
    public function benignDuplicateWithSameHandlerAndNameIsNotACollision(): void
    {
        // Route-cache replay + unconditional extension re-boot registers the SAME
        // route (identical handler + name) twice. That is not a conflict, on any
        // method the convenience helper registers (GET and HEAD).
        $router = new Router();
        $router->get('/booking', ['BookingController', 'form'], 'booking.form');
        $router->get('/booking', ['BookingController', 'form'], 'booking.form');

        self::assertSame([], $router->collisions);
        self::assertSame('booking.form', $router->match(Method::GET, '/booking')->getName());
    }

    #[Test]
    public function snapshotRoundTripPreservesCollisionsAndPrecedence(): void
    {
        $router = new Router();
        $router->add(self::getRoute('/booking', ['ProjectController', 'form'], 'project.booking'));
        $router->add(self::getRoute('/booking', ['ExtensionController', 'stub'], 'extension.booking'));

        $restored = new Router();
        $restored->restoreFromSnapshot($router->snapshot());

        self::assertCount(1, $restored->collisions);
        self::assertSame('project.booking', $restored->match(Method::GET, '/booking')->getName());

        // A further conflicting registration after restore is still detected.
        $restored->add(self::getRoute('/booking', ['AnotherController', 'x'], 'another.booking'));
        self::assertCount(2, $restored->collisions);
    }
}
