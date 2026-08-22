<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteGroup;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteGroup::class)]
#[CoversClass(MatchedRoute::class)]
#[CoversClass(RoutingException::class)]
final class RouterIntegrationTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    // ---------------------------------------------------------------
    // Static route matching
    // ---------------------------------------------------------------

    #[Test]
    public function matchesStaticGetRoute(): void
    {
        $this->router->get('/users', 'UsersController@index');

        $matched = $this->router->match(Method::GET, '/users');

        self::assertSame('UsersController@index', $matched->getHandler());
        self::assertSame([], $matched->parameters);
    }

    #[Test]
    public function matchesStaticPostRoute(): void
    {
        $this->router->post('/users', 'UsersController@store');

        $matched = $this->router->match(Method::POST, '/users');

        self::assertSame('UsersController@store', $matched->getHandler());
    }

    #[Test]
    public function matchesStaticRouteWithTrailingSlashNormalization(): void
    {
        $this->router->get('/dashboard/', 'DashboardController@index');

        $matched = $this->router->match(Method::GET, '/dashboard');

        self::assertSame('DashboardController@index', $matched->getHandler());
    }

    #[Test]
    public function headMethodMatchesGetRoute(): void
    {
        $this->router->get('/health', 'HealthController@check');

        $matched = $this->router->match(Method::HEAD, '/health');

        self::assertSame('HealthController@check', $matched->getHandler());
    }

    // ---------------------------------------------------------------
    // Parameter extraction
    // ---------------------------------------------------------------

    #[Test]
    public function extractsSingleParameter(): void
    {
        $this->router->get('/users/{id}', 'UsersController@show');

        $matched = $this->router->match(Method::GET, '/users/42');

        self::assertSame('42', $matched->parameter('id'));
        self::assertTrue($matched->hasParameter('id'));
        self::assertFalse($matched->hasParameter('slug'));
    }

    #[Test]
    public function extractsMultipleParameters(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', 'PostsController@show');

        $matched = $this->router->match(Method::GET, '/users/5/posts/99');

        self::assertSame('5', $matched->parameter('userId'));
        self::assertSame('99', $matched->parameter('postId'));
    }

    #[Test]
    public function parameterWithConstraintMatchesValidInput(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/orders/{id}',
            handler: 'OrdersController@show',
            constraints: ['id' => '\d+'],
        );
        $this->router->add($route);

        $matched = $this->router->match(Method::GET, '/orders/123');

        self::assertSame('123', $matched->parameter('id'));
    }

    #[Test]
    public function parameterWithConstraintRejectsInvalidInput(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/orders/{id}',
            handler: 'OrdersController@show',
            constraints: ['id' => '\d+'],
        );
        $this->router->add($route);

        $this->expectException(RoutingException::class);
        $this->router->match(Method::GET, '/orders/abc');
    }

    #[Test]
    public function optionalParameterMatchesWithoutValue(): void
    {
        $this->router->get('/blog/{page?}', 'BlogController@index');

        $matched = $this->router->match(Method::GET, '/blog');

        self::assertSame('BlogController@index', $matched->getHandler());
    }

    #[Test]
    public function optionalParameterMatchesWithValue(): void
    {
        $this->router->get('/blog/{page?}', 'BlogController@index');

        $matched = $this->router->match(Method::GET, '/blog/2');

        self::assertSame('2', $matched->parameter('page'));
    }

    #[Test]
    public function parameterDefaultValueFromMatchedRoute(): void
    {
        $this->router->get('/items/{id}', 'ItemsController@show');

        $matched = $this->router->match(Method::GET, '/items/7');

        self::assertNull($matched->parameter('missing'));
        self::assertSame('fallback', $matched->parameter('missing', 'fallback'));
    }

    // ---------------------------------------------------------------
    // Method-not-allowed detection
    // ---------------------------------------------------------------

    #[Test]
    public function throwsMethodNotAllowedWhenPathMatchesButMethodDoesNot(): void
    {
        $this->router->get('/articles', 'ArticlesController@index');
        $this->router->post('/articles', 'ArticlesController@store');

        try {
            $this->router->match(Method::DELETE, '/articles');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertTrue($e->isMethodNotAllowed());
            self::assertStringContainsString('GET', $e->getAllowHeader());
            self::assertStringContainsString('POST', $e->getAllowHeader());
        }
    }

    // ---------------------------------------------------------------
    // Route-not-found detection
    // ---------------------------------------------------------------

    #[Test]
    public function throwsNotFoundForUnregisteredPath(): void
    {
        $this->router->get('/home', 'HomeController@index');

        try {
            $this->router->match(Method::GET, '/nonexistent');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertTrue($e->isNotFound());
            self::assertSame(404, $e->getCode());
        }
    }

    // ---------------------------------------------------------------
    // Named routes and URL generation
    // ---------------------------------------------------------------

    #[Test]
    public function namedRouteCanBeRetrievedByName(): void
    {
        $this->router->get('/login', 'AuthController@login', 'auth.login');

        $route = $this->router->getByName('auth.login');

        self::assertNotNull($route);
        self::assertSame('/login', $route->path);
    }

    #[Test]
    public function namedRouteReturnsNullForUnknownName(): void
    {
        self::assertNull($this->router->getByName('nonexistent'));
    }

    #[Test]
    public function urlGenerationForStaticNamedRoute(): void
    {
        $this->router->get('/dashboard', 'DashboardController@index', 'dashboard');

        $url = $this->router->url('dashboard');

        self::assertSame('/dashboard', $url);
    }

    #[Test]
    public function urlGenerationWithParameters(): void
    {
        $this->router->get('/users/{id}/edit', 'UsersController@edit', 'users.edit');

        $url = $this->router->url('users.edit', ['id' => '42']);

        self::assertSame('/users/42/edit', $url);
    }

    #[Test]
    public function urlGenerationRemovesUnfilledOptionalParameters(): void
    {
        $this->router->get('/search/{query?}', 'SearchController@index', 'search');

        $url = $this->router->url('search');

        self::assertSame('/search', $url);
    }

    #[Test]
    public function urlGenerationThrowsForUnknownRouteName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->url('nonexistent.route');
    }

    // ---------------------------------------------------------------
    // Route groups
    // ---------------------------------------------------------------

    #[Test]
    public function groupPrefixesAllChildRoutes(): void
    {
        $this->router->group('/api', function (Router $r): void {
            $r->get('/users', 'ApiUsersController@index', 'api.users');
            $r->post('/users', 'ApiUsersController@store');
        });

        $matched = $this->router->match(Method::GET, '/api/users');
        self::assertSame('ApiUsersController@index', $matched->getHandler());
        self::assertSame('api.users', $matched->getName());

        $matched = $this->router->match(Method::POST, '/api/users');
        self::assertSame('ApiUsersController@store', $matched->getHandler());
    }

    #[Test]
    public function nestedGroupsCombinePrefixes(): void
    {
        $this->router->group('/api', function (Router $r): void {
            $r->group('/v1', function (Router $inner): void {
                $inner->get('/products', 'ProductsV1Controller@index');
            });
        });

        $matched = $this->router->match(Method::GET, '/api/v1/products');

        self::assertSame('ProductsV1Controller@index', $matched->getHandler());
    }

    #[Test]
    public function routeGroupWithMiddleware(): void
    {
        $group = new RouteGroup(
            prefix: '/admin',
            middleware: ['auth', 'admin'],
        );
        $group->add(Route::get('/settings', 'AdminSettingsController@index', 'admin.settings'));
        $group->add(Route::post('/settings', 'AdminSettingsController@update'));

        $this->router->addGroup($group);

        $matched = $this->router->match(Method::GET, '/admin/settings');

        self::assertSame('AdminSettingsController@index', $matched->getHandler());
        self::assertContains('auth', $matched->getMiddleware());
        self::assertContains('admin', $matched->getMiddleware());
    }

    #[Test]
    public function routeGroupWithAttributes(): void
    {
        $group = new RouteGroup(
            prefix: '/api',
            attributes: ['version' => 'v2'],
        );
        $group->add(Route::get('/data', 'DataController@index'));

        $this->router->addGroup($group);

        $matched = $this->router->match(Method::GET, '/api/data');

        self::assertSame('v2', $matched->getAttributes()['version']);
    }

    #[Test]
    public function nestedRouteGroupsFlattenCorrectly(): void
    {
        $outer = new RouteGroup(prefix: '/api', middleware: ['throttle']);
        $inner = new RouteGroup(prefix: '/v2', middleware: ['auth']);
        $inner->add(Route::get('/items', 'ItemsV2Controller@list'));
        $outer->group($inner);

        $this->router->addGroup($outer);

        $matched = $this->router->match(Method::GET, '/api/v2/items');

        self::assertSame('ItemsV2Controller@list', $matched->getHandler());
        self::assertContains('throttle', $matched->getMiddleware());
        self::assertContains('auth', $matched->getMiddleware());
    }

    // ---------------------------------------------------------------
    // Router locking (strict cache mode)
    // ---------------------------------------------------------------

    #[Test]
    public function lockedRouterRejectsNewRoutes(): void
    {
        $this->router->get('/initial', 'InitialController@index');
        $this->router->lock();

        $this->expectException(RoutingException::class);
        $this->router->get('/late', 'LateController@index');
    }

    #[Test]
    public function lockedRouterStillMatchesExistingRoutes(): void
    {
        $this->router->get('/allowed', 'AllowedController@index');
        $this->router->lock();

        $matched = $this->router->match(Method::GET, '/allowed');

        self::assertSame('AllowedController@index', $matched->getHandler());
    }

    // ---------------------------------------------------------------
    // All HTTP verbs
    // ---------------------------------------------------------------

    /**
     * @return list<array{Method, string}>
     */
    public static function httpVerbProvider(): array
    {
        return [
            [Method::GET, 'get'],
            [Method::POST, 'post'],
            [Method::PUT, 'put'],
            [Method::PATCH, 'patch'],
            [Method::DELETE, 'delete'],
        ];
    }

    #[Test]
    #[DataProvider('httpVerbProvider')]
    public function eachHttpVerbRegistersAndMatchesCorrectly(Method $method, string $routerMethod): void
    {
        $this->router->{$routerMethod}('/resource', 'ResourceController@handle');

        $matched = $this->router->match($method, '/resource');

        self::assertSame('ResourceController@handle', $matched->getHandler());
    }

    #[Test]
    public function anyRouteMatchesAllMethods(): void
    {
        $this->router->any('/catch-all', 'CatchAllController@handle');

        foreach ([Method::GET, Method::POST, Method::PUT, Method::PATCH, Method::DELETE, Method::OPTIONS] as $method) {
            $matched = $this->router->match($method, '/catch-all');
            self::assertSame('CatchAllController@handle', $matched->getHandler());
        }
    }

    // ---------------------------------------------------------------
    // Host-based routing
    // ---------------------------------------------------------------

    #[Test]
    public function hostBasedRouteMatchesCorrectHost(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/dashboard',
            handler: 'TenantDashboardController@index',
            host: '{tenant}.example.com',
        );
        $this->router->add($route);

        $matched = $this->router->match(Method::GET, '/dashboard', 'acme.example.com');

        self::assertSame('acme', $matched->parameter('tenant'));
    }

    #[Test]
    public function hostBasedRouteDoesNotMatchWrongHost(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/dashboard',
            handler: 'TenantDashboardController@index',
            host: 'api.example.com',
        );
        $this->router->add($route);

        $this->expectException(RoutingException::class);
        $this->router->match(Method::GET, '/dashboard', 'other.example.com');
    }

    // ---------------------------------------------------------------
    // Introspection
    // ---------------------------------------------------------------

    #[Test]
    public function countReturnsNumberOfRegisteredRoutes(): void
    {
        self::assertSame(0, $this->router->count());

        $this->router->get('/a', 'A');
        $this->router->post('/b', 'B');
        $this->router->put('/c', 'C');

        self::assertSame(3, $this->router->count());
    }

    #[Test]
    public function routesReturnsAllRegisteredRouteObjects(): void
    {
        $this->router->get('/x', 'X');
        $this->router->delete('/y', 'Y');

        $routes = $this->router->routes();

        self::assertCount(2, $routes);
        self::assertContainsOnlyInstancesOf(Route::class, $routes);
    }

    // ---------------------------------------------------------------
    // loadRoutes (cache restore)
    // ---------------------------------------------------------------

    #[Test]
    public function loadRoutesRestoresPreBuiltRoutes(): void
    {
        $routes = [
            Route::get('/cached/a', 'CachedA', 'cached.a'),
            Route::post('/cached/b', 'CachedB', 'cached.b'),
        ];

        $this->router->loadRoutes($routes);

        self::assertSame(2, $this->router->count());

        $matched = $this->router->match(Method::GET, '/cached/a');
        self::assertSame('CachedA', $matched->getHandler());
        self::assertSame('cached.a', $matched->getName());
    }

    // ---------------------------------------------------------------
    // Explicit model bindings
    // ---------------------------------------------------------------

    #[Test]
    public function modelBindingsAreRegistered(): void
    {
        /** @var class-string $userClass */
        $userClass = implode('\\', ['App', 'Models', 'User']);
        /** @var class-string $postClass */
        $postClass = implode('\\', ['App', 'Models', 'Post']);
        /** @var class-string $resolverClass */
        $resolverClass = implode('\\', ['App', 'Resolvers', 'PostResolver']);
        $this->router->model('user', $userClass);
        $this->router->model('post', $postClass, $resolverClass);

        self::assertCount(2, $this->router->explicitBindings);
        self::assertSame('user', $this->router->explicitBindings[0]->parameter);
        self::assertSame('App\Models\User', $this->router->explicitBindings[0]->modelClass);
        self::assertSame('App\Resolvers\PostResolver', $this->router->explicitBindings[1]->resolverClass);
    }
}
