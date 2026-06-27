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
