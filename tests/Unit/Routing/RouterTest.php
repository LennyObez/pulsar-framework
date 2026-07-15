<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteGroup;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(Router::class)]
#[CoversClass(MatchedRoute::class)]
#[CoversClass(RouteGroup::class)]
#[CoversClass(RoutingException::class)]
final class RouterTest extends TestCase
{
    #[Test]
    public function addRegistersRoute(): void
    {
        $router = new Router();
        $route = Route::get('/', fn() => null);

        $router->add($route);

        self::assertCount(1, $router->routes);
    }

    #[Test]
    public function addRegistersNamedRoute(): void
    {
        $router = new Router();
        $route = Route::get('/', fn() => null, 'home');

        $router->add($route);

        self::assertSame($route, $router->getByName('home'));
    }

    #[Test]
    public function snapshotAndRestoreRoundTripsRouterState(): void
    {
        $router = new Router();
        $router->add(Route::get('/home', fn() => null, 'home'));

        $snapshot = $router->snapshot();

        // Routes registered after the snapshot are discarded on restore — this is
        // how the kernel prevents reboot from accumulating wiring/extension routes.
        $router->add(Route::get('/added', fn() => null, 'added'));
        self::assertCount(2, $router->routes);

        $router->restoreFromSnapshot($snapshot);

        self::assertCount(1, $router->routes);
        self::assertNotNull($router->getByName('home'));
        self::assertNull($router->getByName('added'));
    }

    #[Test]
    public function getMethodRegistersGetRoute(): void
    {
        $router = new Router();
        $handler = fn() => null;

        $router->get('/test', $handler, 'test');

        $routes = $router->routes;
        self::assertCount(1, $routes);
        self::assertSame('/test', $routes[0]->path);
    }

    #[Test]
    public function postMethodRegistersPostRoute(): void
    {
        $router = new Router();
        $router->post('/test', fn() => null);

        $routes = $router->routes;
        self::assertSame([Method::POST], $routes[0]->methods);
    }

    #[Test]
    public function putMethodRegistersPutRoute(): void
    {
        $router = new Router();
        $router->put('/test', fn() => null);

        $routes = $router->routes;
        self::assertSame([Method::PUT], $routes[0]->methods);
    }

    #[Test]
    public function patchMethodRegistersPatchRoute(): void
    {
        $router = new Router();
        $router->patch('/test', fn() => null);

        $routes = $router->routes;
        self::assertSame([Method::PATCH], $routes[0]->methods);
    }

    #[Test]
    public function deleteMethodRegistersDeleteRoute(): void
    {
        $router = new Router();
        $router->delete('/test', fn() => null);

        $routes = $router->routes;
        self::assertSame([Method::DELETE], $routes[0]->methods);
    }

    #[Test]
    public function anyMethodRegistersRouteForAllMethods(): void
    {
        $router = new Router();
        $router->any('/test', fn() => null);

        $routes = $router->routes;
        self::assertContains(Method::GET, $routes[0]->methods);
        self::assertContains(Method::POST, $routes[0]->methods);
    }

    #[Test]
    public function matchReturnsMatchedRoute(): void
    {
        $router = new Router();
        $handler = fn() => null;
        $router->get('/users', $handler, 'users.index');

        $matched = $router->match(Method::GET, '/users');

        self::assertInstanceOf(MatchedRoute::class, $matched);
        self::assertSame($handler, $matched->getHandler());
        self::assertSame('users.index', $matched->getName());
    }

    #[Test]
    public function matchExtractsParameters(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn() => null);

        $matched = $router->match(Method::GET, '/users/123');

        self::assertSame('123', $matched->parameter('id'));
        self::assertTrue($matched->hasParameter('id'));
        self::assertFalse($matched->hasParameter('missing'));
    }

    #[Test]
    public function matchThrowsNotFoundForUnknownPath(): void
    {
        $router = new Router();
        $router->get('/', fn() => null);

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        $router->match(Method::GET, '/unknown');
    }

    #[Test]
    public function headMatchesGetRoutesFromBothRegistrationStyles(): void
    {
        // RFC 9110 §9.3.2 regression: the sugar path always worked, but a route
        // registered through the explicit constructor (the middleware-attach
        // style every contact/booking page uses) answered 405 on HEAD.
        $router = new Router();
        $router->get('/sugar', fn() => null);
        $router->add(new Route(methods: [Method::GET], path: '/explicit', handler: fn() => null, middleware: ['web']));

        self::assertNotNull($router->match(Method::HEAD, '/sugar'));
        self::assertNotNull($router->match(Method::HEAD, '/explicit'));
    }

    #[Test]
    public function headStillReturns405OnPostOnlyRoutes(): void
    {
        $router = new Router();
        $router->add(new Route([Method::POST], '/submit', fn() => null));

        try {
            $router->match(Method::HEAD, '/submit');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertSame(405, $e->getCode());
            self::assertStringNotContainsString('HEAD', $e->getAllowHeader());
        }
    }

    #[Test]
    public function allowHeaderListsHeadWhereverGetIsServed(): void
    {
        // The 405 Allow header must not contradict the router: HEAD is served
        // wherever GET is, including explicit-constructor registrations.
        $router = new Router();
        $router->add(new Route(methods: [Method::GET], path: '/page', handler: fn() => null));

        try {
            $router->match(Method::DELETE, '/page');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertContains(Method::HEAD, $e->allowedMethods);
            self::assertStringContainsString('HEAD', $e->getAllowHeader());
        }
    }

    #[Test]
    public function matchThrowsMethodNotAllowedForWrongMethod(): void
    {
        $router = new Router();
        $router->get('/test', fn() => null);
        $router->post('/test', fn() => null);

        try {
            $router->match(Method::DELETE, '/test');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertSame(405, $e->getCode());
            self::assertTrue($e->isMethodNotAllowed());
            self::assertContains(Method::GET, $e->allowedMethods);
            self::assertContains(Method::POST, $e->allowedMethods);
            self::assertStringContainsString('GET', $e->getAllowHeader());
        }
    }

    #[Test]
    public function addGroupRegistersGroupedRoutes(): void
    {
        $router = new Router();
        $group = new RouteGroup('/api');
        $group->add(Route::get('/users', fn() => null));
        $group->add(Route::get('/posts', fn() => null));

        $router->addGroup($group);

        $routes = $router->routes;
        self::assertCount(2, $routes);
        self::assertSame('/api/users', $routes[0]->path);
        self::assertSame('/api/posts', $routes[1]->path);
    }

    #[Test]
    public function nestedGroupsApplyPrefixes(): void
    {
        $router = new Router();
        $api = new RouteGroup('/api');
        $v1 = new RouteGroup('/v1');
        $v1->add(Route::get('/users', fn() => null));
        $api->group($v1);

        $router->addGroup($api);

        $routes = $router->routes;
        self::assertSame('/api/v1/users', $routes[0]->path);
    }

    #[Test]
    public function groupAppliesMiddleware(): void
    {
        $router = new Router();
        $group = new RouteGroup('/api', ['auth', 'throttle']);
        $group->add(new Route([Method::GET], '/users', fn() => null, middleware: ['log']));

        $router->addGroup($group);

        $routes = $router->routes;
        self::assertSame(['auth', 'throttle', 'log'], $routes[0]->middleware);
    }

    #[Test]
    public function urlGeneratesPathForNamedRoute(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn() => null, 'users.show');

        $url = $router->url('users.show', ['id' => '123']);

        self::assertSame('/users/123', $url);
    }

    #[Test]
    public function urlGeneratesPathWithMultipleParameters(): void
    {
        $router = new Router();
        $router->get('/users/{userId}/posts/{postId}', fn() => null, 'posts.show');

        $url = $router->url('posts.show', ['userId' => '1', 'postId' => '42']);

        self::assertSame('/users/1/posts/42', $url);
    }

    #[Test]
    public function urlThrowsExceptionForUnknownRoute(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route "unknown" not found');

        $router->url('unknown');
    }

    #[Test]
    public function urlPercentEncodesPathSeparators(): void
    {
        // F2.7: a raw `/` in a parameter value would punch out of the
        // segment and change which route the URL points at.
        $router = new Router();
        $router->get('/users/{id}', fn() => null, 'users.show');

        $url = $router->url('users.show', ['id' => 'a/b']);

        self::assertSame('/users/a%2Fb', $url);
    }

    #[Test]
    public function urlPercentEncodesTraversalSequence(): void
    {
        // F2.7: `..` in a parameter value would let the URL refer to
        // a parent path. Encoding renders it inert.
        $router = new Router();
        $router->get('/users/{id}', fn() => null, 'users.show');

        $url = $router->url('users.show', ['id' => '../admin']);

        self::assertSame('/users/..%2Fadmin', $url);
    }

    #[Test]
    public function urlPercentEncodesQueryAndFragmentDelimiters(): void
    {
        $router = new Router();
        $router->get('/posts/{slug}', fn() => null, 'posts.show');

        $url = $router->url('posts.show', ['slug' => 'a?b#c']);

        self::assertSame('/posts/a%3Fb%23c', $url);
    }

    #[Test]
    public function urlPercentEncodesSpacesAndUnicode(): void
    {
        $router = new Router();
        $router->get('/search/{term}', fn() => null, 'search');

        $url = $router->url('search', ['term' => 'jane doé']);

        // rawurlencode produces %20 (not '+') for spaces and percent-
        // encodes UTF-8 bytes individually.
        self::assertSame('/search/jane%20do%C3%A9', $url);
    }

    #[Test]
    public function urlPreservesUnreservedCharacters(): void
    {
        $router = new Router();
        $router->get('/items/{id}', fn() => null, 'items.show');

        // RFC 3986 unreserved set: ALPHA, DIGIT, '-', '.', '_', '~'.
        $url = $router->url('items.show', ['id' => 'A-Z_0.9~end']);

        self::assertSame('/items/A-Z_0.9~end', $url);
    }

    #[Test]
    public function countReturnsNumberOfRoutes(): void
    {
        $router = new Router();

        self::assertSame(0, $router->count());

        $router->get('/a', fn() => null);
        $router->get('/b', fn() => null);

        self::assertSame(2, $router->count());
    }

    #[Test]
    public function getNamedRoutesReturnsAllNamedRoutes(): void
    {
        $router = new Router();
        $router->get('/a', fn() => null, 'route.a');
        $router->get('/b', fn() => null);
        $router->get('/c', fn() => null, 'route.c');

        $named = $router->namedRoutes;

        self::assertArrayHasKey('route.a', $named);
        self::assertArrayHasKey('route.c', $named);
        self::assertCount(2, $named);
    }

    #[Test]
    public function getByNameReturnsNullForUnknownRoute(): void
    {
        $router = new Router();

        self::assertNull($router->getByName('unknown'));
    }

    // --- Route Constraints in Router ---

    #[Test]
    public function matchRespectsConstraints(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: fn() => 'constrained',
            constraints: ['id' => '\d+'],
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{slug}',
            handler: fn() => 'fallback',
        ));

        // Numeric id matches the constrained route
        $matched = $router->match(Method::GET, '/users/123');
        self::assertSame('123', $matched->parameter('id'));

        // Non-numeric falls through to the fallback route
        $matched = $router->match(Method::GET, '/users/john');
        self::assertSame('john', $matched->parameter('slug'));
    }

    // --- Host-Based Routing in Router ---

    #[Test]
    public function matchWithHostSelectsCorrectRoute(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => 'api',
            host: 'api.example.com',
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => 'web',
        ));

        // With matching host, picks the host-constrained route
        $matched = $router->match(Method::GET, '/', 'api.example.com');
        /** @var callable(): string $apiHandler */
        $apiHandler = $matched->getHandler();
        self::assertSame('api', $apiHandler());

        // With a different host, picks the fallback (no host constraint)
        $matched = $router->match(Method::GET, '/', 'www.example.com');
        /** @var callable(): string $webHandler */
        $webHandler = $matched->getHandler();
        self::assertSame('web', $webHandler());
    }

    #[Test]
    public function matchWithHostExtractsHostParameters(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: fn() => null,
            host: '{tenant}.app.com',
        ));

        $matched = $router->match(Method::GET, '/dashboard', 'acme.app.com');

        self::assertSame('acme', $matched->parameter('tenant'));
    }

    #[Test]
    public function matchWithPortedHostMatchesHostConstrainedRoute(): void
    {
        // FR-1: a Host header on a non-default port must still match a route
        // declared against the port-less host.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => 'api',
            host: 'api.example.com',
        ));

        $matched = $router->match(Method::GET, '/', 'api.example.com:8000');

        /** @var callable(): string $handler */
        $handler = $matched->getHandler();
        self::assertSame('api', $handler());
    }

    #[Test]
    public function matchWithPortedHostCapturesHostParameter(): void
    {
        // FR-1: subdomain capture must work when the Host header carries a port.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: fn() => null,
            host: '{tenant}.app.com',
        ));

        $matched = $router->match(Method::GET, '/dashboard', 'acme.app.com:8000');

        self::assertSame('acme', $matched->parameter('tenant'));
    }

    #[Test]
    public function matchWithPortedIpv6HostMatchesHostConstrainedRoute(): void
    {
        // FR-1: a bracketed IPv6 authority strips only the trailing port.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => 'local',
            host: '[::1]',
        ));

        $matched = $router->match(Method::GET, '/', '[::1]:8000');

        /** @var callable(): string $handler */
        $handler = $matched->getHandler();
        self::assertSame('local', $handler());
    }

    #[Test]
    public function matchHonorsRegistrationOrderBetweenCatchAllAndStaticFirstSegment(): void
    {
        // FR-2: an earlier-registered catch-all (/{lang}/{slug}) must win over a
        // later-registered static-first-segment route (/blog/{slug}) for
        // /blog/hello — first-registered-wins across the bucket split. Before the
        // fix the first-segment bucket was always scanned ahead of the catch-all
        // bucket, so /blog/{slug} wrongly won.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/{lang}/{slug}',
            handler: fn() => 'catch-all',
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: '/blog/{slug}',
            handler: fn() => 'blog',
        ));

        $matched = $router->match(Method::GET, '/blog/hello');

        /** @var callable(): string $handler */
        $handler = $matched->getHandler();
        self::assertSame('catch-all', $handler());
        self::assertSame('blog', $matched->parameter('lang'));
        self::assertSame('hello', $matched->parameter('slug'));
    }

    #[Test]
    public function matchPrefersStaticRouteOverDynamicCatchAllWithHostHeaderPresent(): void
    {
        // FR-39: a host-less static route keeps its O(1) precedence over a dynamic
        // catch-all even when the request carries a Host header. Before the fix the
        // static fast path was skipped whenever a Host was present, so the
        // earlier-registered catch-all wrongly captured the static path.
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/{slug}',
            handler: fn() => 'catch-all',
        ));
        $router->add(Route::get('/about', fn() => 'about'));

        $matched = $router->match(Method::GET, '/about', 'example.com:8080');

        /** @var callable(): string $handler */
        $handler = $matched->getHandler();
        self::assertSame('about', $handler());
    }

    #[Test]
    public function matchWithoutHostSkipsHostConstrainedRoutes(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => 'host-only',
            host: 'api.example.com',
        ));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        // No host provided, and the only route requires a host
        $router->match(Method::GET, '/');
    }

    #[Test]
    public function groupPropagatesHostToRoutes(): void
    {
        $router = new Router();
        $group = new RouteGroup('/api', host: 'api.example.com');
        $group->add(Route::get('/users', fn() => null));

        $router->addGroup($group);

        $routes = $router->routes;
        self::assertSame('api.example.com', $routes[0]->host);
    }

    #[Test]
    public function routeHostOverridesGroupHost(): void
    {
        $router = new Router();
        $group = new RouteGroup('/api', host: 'api.example.com');
        $group->add(new Route(
            methods: [Method::GET],
            path: '/special',
            handler: fn() => null,
            host: 'special.example.com',
        ));

        $router->addGroup($group);

        $routes = $router->routes;
        self::assertSame('special.example.com', $routes[0]->host);
    }

    #[Test]
    public function matchWithHostReturns404WhenNoHostMatches(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => null,
            host: 'api.example.com',
        ));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        $router->match(Method::GET, '/', 'other.example.com');
    }

    // --- Domain-Aware URL Generation ---

    #[Test]
    public function urlWithDomainConfigGeneratesFullyQualifiedUrl(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/threads/{id}',
            handler: fn() => null,
            name: 'forum.thread.show',
            attributes: ['scope' => 'forum'],
        ));

        $domainConfig = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
            scheme: 'https',
        );

        $url = $router->url('forum.thread.show', ['id' => '1'], $domainConfig);

        self::assertSame('https://forum.example.com/threads/1', $url);
    }

    #[Test]
    public function urlWithDomainConfigFallsBackToRelativePathWhenNoScopeAttribute(): void
    {
        $router = new Router();
        $router->get('/dashboard', fn() => null, 'dashboard');

        $domainConfig = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );

        $url = $router->url('dashboard', [], $domainConfig);

        self::assertSame('/dashboard', $url);
    }

    #[Test]
    public function urlWithDomainConfigFallsBackWhenScopeNotMapped(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/settings',
            handler: fn() => null,
            name: 'settings',
            attributes: ['scope' => 'unmapped-scope'],
        ));

        $domainConfig = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
        );

        $url = $router->url('settings', [], $domainConfig);

        self::assertSame('/settings', $url);
    }

    #[Test]
    public function urlWithNullDomainConfigReturnsRelativePath(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/threads/{id}',
            handler: fn() => null,
            name: 'forum.thread.show',
            attributes: ['scope' => 'forum'],
        ));

        $url = $router->url('forum.thread.show', ['id' => '1']);

        self::assertSame('/threads/1', $url);
    }

    #[Test]
    public function urlWithDomainConfigNoSubdomainMappingsReturnsRelative(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/threads/{id}',
            handler: fn() => null,
            name: 'forum.thread.show',
            attributes: ['scope' => 'forum'],
        ));

        $domainConfig = new DomainConfig(defaultDomain: 'example.com');

        $url = $router->url('forum.thread.show', ['id' => '1'], $domainConfig);

        self::assertSame('/threads/1', $url);
    }

    #[Test]
    public function urlWithDomainConfigHttpScheme(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: fn() => null,
            name: 'api.users',
            attributes: ['scope' => 'api'],
        ));

        $domainConfig = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['api' => ['api']],
            scheme: 'http',
        );

        $url = $router->url('api.users', [], $domainConfig);

        self::assertSame('http://api.example.com/api/users', $url);
    }
}
