<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

/**
 * What a locked router does with a registration, once it holds a cached table.
 *
 * A strict route cache is authoritative and the router is locked so nothing can
 * add a route the verified table does not contain. The lock used to refuse
 * every registration, which refused the framework's own boot first: the wirings
 * that registered these routes at `pulsar optimize` time register them again on
 * the next boot, so `I18nWiring` — third in the boot order — aborted every
 * strict-cached boot before anything else could be judged.
 *
 * The rule these tests pin down is identity, not arrival. A registration equal
 * in every field to a route already in the table adds nothing and is dropped; a
 * registration differing anywhere is drift between the deployed code and the
 * cache it booted from, and is still refused.
 */
#[CoversClass(Router::class)]
#[CoversClass(RoutingException::class)]
final class RouterStrictCacheReplayTest extends TestCase
{
    #[Test]
    public function aRegistrationIdenticalToACachedRouteIsAccepted(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $router->add($this->cachedRoute());

        self::assertTrue($router->locked);
        self::assertSame(1, $router->count());
    }

    #[Test]
    public function theAcceptedReplayDoesNotDuplicateTheRouteInTheTable(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $router->add($this->cachedRoute());
        $router->add($this->cachedRoute());
        $router->add($this->cachedRoute());

        self::assertSame(1, $router->count());
        self::assertCount(1, $router->routes());
        self::assertSame([], $router->collisions);
    }

    #[Test]
    public function theCachedRouteStillMatchesAfterAReplay(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $router->add($this->cachedRoute());

        $matched = $router->match(Method::GET, '/api/i18n/en');

        self::assertSame('api.i18n.locale', $matched->route->name);
        self::assertSame(['locale' => 'en'], $matched->parameters);
    }

    #[Test]
    public function aRouteTheCachedTableDoesNotHoldIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(423);

        $router->add(Route::get('/admin/backdoor', 'SomeController'));
    }

    #[Test]
    public function theRefusalNamesTheRouteThatDrifted(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessageIsOrContains('/admin/backdoor');

        $router->add(Route::get('/admin/backdoor', 'SomeController'));
    }

    /**
     * The defect this rule had to survive: a wiring registered
     * `[I18nController::class, 'show']`, the method did not exist, and the fix
     * changed the handler. A cache written before that fix holds the old
     * handler, and serving it would be a 500 on every caller — so the boot must
     * refuse rather than treat same-path as same-route.
     */
    #[Test]
    public function aRouteWhoseHandlerChangedSinceTheCacheIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute(handler: ['App\I18nController', 'show']));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(423);

        $router->add($this->cachedRoute(handler: 'App\I18nController'));
    }

    #[Test]
    public function aRouteWhoseNameChangedSinceTheCacheIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $this->expectException(RoutingException::class);

        $router->add($this->cachedRoute(name: 'api.i18n.bundle'));
    }

    #[Test]
    public function aRouteWhoseMiddlewareChangedSinceTheCacheIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute(middleware: ['throttle']));

        $this->expectException(RoutingException::class);

        $router->add($this->cachedRoute(middleware: []));
    }

    /**
     * The access declaration lives in the route's attributes, so a route that
     * was cached Public and is now registered Authenticated (or the reverse,
     * which is the dangerous direction) is a different route. Comparing only
     * handler and name would have accepted the reverse silently.
     */
    #[Test]
    public function aRouteWhoseAccessDeclarationChangedSinceTheCacheIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute(access: RouteAccess::Authenticated));

        $this->expectException(RoutingException::class);

        $router->add($this->cachedRoute(access: RouteAccess::Public));
    }

    #[Test]
    public function aRouteWhoseConstraintsChangedSinceTheCacheIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute(constraints: ['locale' => '[a-z]{2}']));

        $this->expectException(RoutingException::class);

        $router->add($this->cachedRoute(constraints: ['locale' => '.+']));
    }

    #[Test]
    public function aRegistrationWideningTheCachedMethodsIsRefused(): void
    {
        $router = $this->lockedRouterHolding(
            new Route(methods: [Method::GET], path: '/reports', handler: 'ReportController'),
        );

        $this->expectException(RoutingException::class);

        $router->add(
            new Route(methods: [Method::GET, Method::POST], path: '/reports', handler: 'ReportController'),
        );
    }

    #[Test]
    public function aRegistrationOnADifferentHostIsRefused(): void
    {
        $router = $this->lockedRouterHolding($this->cachedRoute());

        $this->expectException(RoutingException::class);

        $router->add($this->cachedRoute(host: 'admin.example.com'));
    }

    /**
     * The lock still means what it meant on an empty table: with nothing cached
     * there is nothing a registration can be a replay of.
     */
    #[Test]
    public function aLockedRouterWithNoCachedTableRefusesEverything(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(423);

        $router->add($this->cachedRoute());
    }

    private function lockedRouterHolding(Route $route): Router
    {
        $router = new Router();
        $router->loadRoutes([$route]);
        $router->lock();

        return $router;
    }

    /**
     * @param list<string>          $middleware
     * @param array<string, string> $constraints
     */
    private function cachedRoute(
        mixed $handler = 'App\I18nController',
        ?string $name = 'api.i18n.locale',
        array $middleware = [],
        array $constraints = [],
        RouteAccess $access = RouteAccess::Public,
        ?string $host = null,
    ): Route {
        return new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/api/i18n/{locale}',
            handler: $handler,
            name: $name,
            attributes: [
                RouteAccess::ATTRIBUTE => $access,
                RouteAccess::REASON_ATTRIBUTE => 'Client-side translation bundle for anonymous visitors.',
            ],
            middleware: $middleware,
            constraints: $constraints,
            host: $host,
        );
    }
}
