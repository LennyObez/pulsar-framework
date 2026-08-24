<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\CompiledRouteTree;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteCompiler;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function ksort;

/**
 * The live {@see Router} and the build-time {@see CompiledRouteTree} must
 * resolve the same route table identically — same matched route and same
 * extracted parameters — since their docblocks advertise equivalence.
 */
#[CoversClass(Router::class)]
#[CoversClass(CompiledRouteTree::class)]
#[CoversClass(RouteCompiler::class)]
final class RouterCompiledParityTest extends TestCase
{
    #[Test]
    public function liveRouterAndCompiledTreeAgreeOnSharedTable(): void
    {
        // An earlier-registered catch-all precedes a later static-first-segment
        // route so the registration-order contract is exercised: before the
        // matchers were aligned, the live Router (bucket order) and the compiled
        // tree (registration order) disagreed on /blog/2.
        // String handlers (not closures): RouteCompiler intentionally skips
        // closure handlers since they cannot be serialized into the compiled tree.
        $routes = [
            new Route(methods: [Method::GET], path: '/{lang}/{slug}', handler: 'C::catchAll', name: 'catch_all'),
            new Route(methods: [Method::GET], path: '/blog/{page?}', handler: 'C::blog', name: 'blog'),
            Route::get('/about', 'C::about', 'about'),
            new Route(methods: [Method::GET], path: '/', handler: 'C::home', name: 'home'),
        ];

        $router = new Router();
        foreach ($routes as $route) {
            $router->add($route);
        }

        $tree = new RouteCompiler()->compile($routes);

        $cases = [
            [Method::GET, '/about'],
            [Method::GET, '/'],
            [Method::GET, '/blog'],
            [Method::GET, '/blog/2'],
            [Method::GET, '/fr/hello'],
            [Method::GET, '/missing/deep/path'],
        ];

        foreach ($cases as [$method, $path]) {
            $live = $this->describe(static fn(): MatchedRoute => $router->match($method, $path));
            $compiled = $this->describe(static fn(): MatchedRoute => $tree->match($method, $path));

            self::assertSame($live['path'], $compiled['path'], "matched route differs for {$path}");
            self::assertSame($live['params'], $compiled['params'], "extracted params differ for {$path}");
        }
    }

    #[Test]
    public function bothMatchersReadTheCustomBindingKeySyntaxTheSameWay(): void
    {
        // `{param:key}` names the column model binding resolves by. It is a
        // declaration, not part of the URL, so both matchers must strip it and
        // capture the parameter under its bare name — a compiled table that
        // matched only the literal `/users/{user:slug}` would take every
        // custom-key route out of service in production alone.
        $routes = [
            new Route(methods: [Method::GET], path: '/users/{user:slug}', handler: 'C::user', name: 'user'),
            new Route(
                methods: [Method::GET],
                path: '/users/{user:slug}/posts/{post}',
                handler: 'C::post',
                name: 'post',
            ),
            new Route(methods: [Method::GET], path: '/posts/{post:slug?}', handler: 'C::posts', name: 'posts'),
        ];

        $router = new Router();
        foreach ($routes as $route) {
            $router->add($route);
        }

        $tree = new RouteCompiler()->compile($routes);

        $cases = [
            [Method::GET, '/users/john-doe'],
            [Method::GET, '/users/john-doe/posts/20'],
            [Method::GET, '/posts'],
            [Method::GET, '/posts/hello-world'],
            [Method::GET, '/users/john/doe'],
        ];

        foreach ($cases as [$method, $path]) {
            $live = $this->describe(static fn(): MatchedRoute => $router->match($method, $path));
            $compiled = $this->describe(static fn(): MatchedRoute => $tree->match($method, $path));

            self::assertSame($live['path'], $compiled['path'], "matched route differs for {$path}");
            self::assertSame($live['params'], $compiled['params'], "extracted params differ for {$path}");
        }

        self::assertSame(
            ['post' => '20', 'user' => 'john-doe'],
            $this->describe(static fn(): MatchedRoute => $tree->match(Method::GET, '/users/john-doe/posts/20'))['params'],
        );
    }

    /**
     * @param callable(): MatchedRoute $match
     *
     * @return array{path: string|null, params: array<string, string>}
     */
    private function describe(callable $match): array
    {
        try {
            $matched = $match();
        } catch (RoutingException) {
            return ['path' => null, 'params' => []];
        }

        $params = $matched->parameters;
        ksort($params);

        return ['path' => $matched->route->path, 'params' => $params];
    }
}
