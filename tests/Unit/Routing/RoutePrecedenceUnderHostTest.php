<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\CompiledRouteTree;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteCompiler;
use Pulsar\Routing\Router;

/**
 * Route precedence must not depend on the request `Host` header.
 *
 * Which handler owns a path is an access-control fact in this framework: a route
 * carries its authorization metadata in its attributes, so serving a different
 * route than the table says owns the path serves a different access declaration
 * with it. The `Host` header is client-supplied. If it can move a request from
 * one handler to another, an attacker chooses the handler.
 *
 * The precedence contract, unchanged in all four combinations below:
 *
 * 1. a route constrained to a host that matches the request `Host`;
 * 2. then the host-less static (literal-path) route;
 * 3. then host-less dynamic routes, in registration order (ADR-0034).
 *
 * The four combinations are `Host` header present or absent, crossed with a
 * host-constrained route present or absent **elsewhere** in the table — that
 * second axis is the one that used to matter, because the router's O(1) static
 * fast path was disabled by the mere existence of any host-constrained route
 * anywhere, and the fallback scan then found the later dynamic route first.
 */
#[CoversClass(Router::class)]
#[CoversClass(CompiledRouteTree::class)]
#[CoversClass(RouteCompiler::class)]
final class RoutePrecedenceUnderHostTest extends TestCase
{
    /**
     * The four combinations of the two axes: a host-constrained route present
     * or absent elsewhere in the table, crossed with a request `Host` header
     * present or absent. Every one of them must resolve the same way.
     *
     * @return iterable<string, array{bool, string|null}>
     */
    public static function hostCombinations(): iterable
    {
        yield 'no host-constrained route, no Host header' => [false, null];
        yield 'no host-constrained route, Host header present' => [false, 'www.example.com'];
        yield 'host-constrained route elsewhere, no Host header' => [true, null];
        yield 'host-constrained route elsewhere, Host header present' => [true, 'www.example.com'];
    }

    #[Test]
    #[DataProvider('hostCombinations')]
    public function staticRouteOutranksDynamicRouteInEveryHostCombination(
        bool $hostConstrainedRouteElsewhere,
        ?string $requestHost,
    ): void {
        $router = new Router();

        // Registered FIRST, so registration order cannot be what decides this:
        // the literal path wins because it is the more specific route.
        $router->add(new Route(methods: [Method::GET], path: '/users/{id}', handler: 'C::dynamic'));
        $router->add(Route::get('/users/profile', 'C::static'));

        if ($hostConstrainedRouteElsewhere) {
            // An unrelated route on an unrelated path. It must not change which
            // handler owns /users/profile.
            $router->add(new Route(
                methods: [Method::GET],
                path: '/admin',
                handler: 'C::admin',
                host: 'admin.example.com',
            ));
        }

        $matched = $router->match(Method::GET, '/users/profile', $requestHost);

        self::assertSame('C::static', $matched->getHandler());
        self::assertSame([], $matched->parameters);
    }

    #[Test]
    #[DataProvider('hostCombinations')]
    public function compiledTreeAgreesWithTheLiveRouterInEveryHostCombination(
        bool $hostConstrainedRouteElsewhere,
        ?string $requestHost,
    ): void {
        $routes = [
            new Route(methods: [Method::GET], path: '/users/{id}', handler: 'C::dynamic'),
            Route::get('/users/profile', 'C::static'),
        ];

        if ($hostConstrainedRouteElsewhere) {
            $routes[] = new Route(
                methods: [Method::GET],
                path: '/admin',
                handler: 'C::admin',
                host: 'admin.example.com',
            );
        }

        $router = new Router();

        foreach ($routes as $route) {
            $router->add($route);
        }

        $tree = new RouteCompiler()->compile($routes);

        $live = $router->match(Method::GET, '/users/profile', $requestHost);
        $compiled = $tree->match(Method::GET, '/users/profile', $requestHost);

        self::assertSame('C::static', $compiled->getHandler());
        self::assertSame($live->getHandler(), $compiled->getHandler());
        self::assertSame($live->parameters, $compiled->parameters);
    }

    #[Test]
    #[DataProvider('hostCombinations')]
    public function theHostHeaderCannotSwapInADifferentAccessDeclaration(
        bool $hostConstrainedRouteElsewhere,
        ?string $requestHost,
    ): void {
        // Why the precedence flip was an access-control defect and not only a
        // correctness one: a route carries its {@see RouteAccess} declaration
        // and middleware list in its own attributes, so answering with the other
        // route answers under the other route's authorization. Here the
        // placeholder route is Public with no middleware and the literal one is
        // Authenticated behind `auth`.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: 'C::publicProfile',
            attributes: [RouteAccess::ATTRIBUTE => RouteAccess::Public->value],
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/settings',
            handler: 'C::settings',
            attributes: [RouteAccess::ATTRIBUTE => RouteAccess::Authenticated->value],
            middleware: ['auth'],
        ));

        if ($hostConstrainedRouteElsewhere) {
            $router->add(new Route(
                methods: [Method::GET],
                path: '/admin',
                handler: 'C::admin',
                host: 'admin.example.com',
            ));
        }

        $matched = $router->match(Method::GET, '/users/settings', $requestHost);

        self::assertSame('C::settings', $matched->getHandler());
        self::assertSame(['auth'], $matched->route->middleware);
        self::assertSame(
            RouteAccess::Authenticated->value,
            $matched->route->attributes[RouteAccess::ATTRIBUTE] ?? null,
        );
    }

    #[Test]
    public function hostConstrainedRouteStillOutranksTheHostLessStaticRouteOnTheSamePath(): void
    {
        // Tier 1 beats tier 2: a route that matches only this Host is more
        // specific than the literal path that answers every host. Registered
        // LAST so the win cannot be attributed to registration order.
        $router = new Router();
        $router->add(Route::get('/dashboard', 'C::public'));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: 'C::tenant',
            host: '{tenant}.app.com',
        ));

        $matched = $router->match(Method::GET, '/dashboard', 'acme.app.com');

        self::assertSame('C::tenant', $matched->getHandler());
        self::assertSame('acme', $matched->parameter('tenant'));

        // A host the constrained route does not claim falls through to tier 2.
        $fallback = $router->match(Method::GET, '/dashboard', 'www.example.com');

        self::assertSame('C::public', $fallback->getHandler());
    }

    #[Test]
    public function registrationOrderStillDecidesBetweenDynamicRoutesWhenAHostHeaderIsPresent(): void
    {
        // Tier 3 is unchanged by the fix: first-registered-wins among host-less
        // dynamic routes (ADR-0034), with or without a Host header, and with a
        // host-constrained route in the table forcing the scan path.
        $router = new Router();
        $router->add(new Route(methods: [Method::GET], path: '/{lang}/{slug}', handler: 'C::catchAll'));
        $router->add(new Route(methods: [Method::GET], path: '/blog/{slug}', handler: 'C::blog'));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/admin',
            handler: 'C::admin',
            host: 'admin.example.com',
        ));

        $withoutHost = $router->match(Method::GET, '/blog/hello');
        $withHost = $router->match(Method::GET, '/blog/hello', 'www.example.com');

        self::assertSame('C::catchAll', $withoutHost->getHandler());
        self::assertSame('C::catchAll', $withHost->getHandler());
        self::assertSame('blog', $withHost->parameter('lang'));
        self::assertSame('hello', $withHost->parameter('slug'));
    }

    #[Test]
    public function aHostConstrainedCatchAllDoesNotStealAPathTheRequestHostDoesNotClaim(): void
    {
        // The tier-1 pass must still test the host, not merely the pattern: a
        // catch-all bound to another host is not a candidate for this request,
        // so the literal path answers it.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/{slug}',
            handler: 'C::tenantCatchAll',
            host: 'tenant.app.com',
        ));
        $router->add(Route::get('/about', 'C::about'));

        $matched = $router->match(Method::GET, '/about', 'www.example.com');

        self::assertSame('C::about', $matched->getHandler());

        $onTenantHost = $router->match(Method::GET, '/about', 'tenant.app.com');

        self::assertSame('C::tenantCatchAll', $onTenantHost->getHandler());
    }
}
