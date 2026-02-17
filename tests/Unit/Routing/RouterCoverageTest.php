<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use RuntimeException;
use stdClass;

/**
 * Additional coverage tests for Router — lock(), model(), loadRoutes(),
 * group() callback, and url() edge cases.
 */
#[CoversClass(Router::class)]
#[CoversClass(ExplicitBinding::class)]
final class RouterCoverageTest extends TestCase
{
    // --- Lock Mode ---

    #[Test]
    public function lockPreventsAddingRoutes(): void
    {
        $router = new Router();
        $router->lock();

        self::assertTrue($router->locked);

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(423);

        $router->add(Route::get('/locked', fn() => null));
    }

    #[Test]
    public function lockPreventsGetShorthand(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);

        $router->get('/test', fn() => null);
    }

    #[Test]
    public function lockPreventsPostShorthand(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);

        $router->post('/test', fn() => null);
    }

    #[Test]
    public function lockPreventsAnyShorthand(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);

        $router->any('/test', fn() => null);
    }

    #[Test]
    public function lockedRouterDefaultsToFalse(): void
    {
        $router = new Router();

        self::assertFalse($router->locked);
    }

    // --- Model Binding Registration ---

    #[Test]
    public function modelRegistersExplicitBinding(): void
    {
        $router = new Router();

        $result = $router->model('user', stdClass::class);

        self::assertSame($router, $result);
        self::assertCount(1, $router->explicitBindings);

        $binding = $router->explicitBindings[0];
        self::assertSame('user', $binding->parameter);
        self::assertSame(stdClass::class, $binding->modelClass);
        self::assertNull($binding->resolverClass);
    }

    #[Test]
    public function modelRegistersWithCustomResolver(): void
    {
        $router = new Router();

        $router->model('post', stdClass::class, ArrayObject::class);

        $binding = $router->explicitBindings[0];
        self::assertSame(ArrayObject::class, $binding->resolverClass);
    }

    #[Test]
    public function multipleModelBindingsAccumulate(): void
    {
        $router = new Router();

        $router->model('user', stdClass::class);
        $router->model('post', ArrayObject::class);
        $router->model('comment', RuntimeException::class);

        self::assertCount(3, $router->explicitBindings);
        self::assertSame('user', $router->explicitBindings[0]->parameter);
        self::assertSame('post', $router->explicitBindings[1]->parameter);
        self::assertSame('comment', $router->explicitBindings[2]->parameter);
    }

    // --- loadRoutes ---

    #[Test]
    public function loadRoutesPopulatesRouteTable(): void
    {
        $router = new Router();
        $routes = [
            Route::get('/a', fn() => 'a', 'route.a'),
            Route::post('/b', fn() => 'b'),
            new Route([Method::GET], '/users/{id}', fn() => 'user'),
        ];

        $router->loadRoutes($routes);

        self::assertCount(3, $router->routes);
        self::assertSame($routes[0], $router->getByName('route.a'));
    }

    #[Test]
    public function loadRoutesEnablesMatching(): void
    {
        $router = new Router();
        $router->loadRoutes([
            Route::get('/home', fn() => 'home', 'home'),
        ]);

        $matched = $router->match(Method::GET, '/home');

        self::assertSame('home', $matched->getName());
    }

    #[Test]
    public function loadRoutesIndexesByMethod(): void
    {
        $router = new Router();
        $router->loadRoutes([
            Route::get('/read', fn() => null),
            Route::post('/write', fn() => null),
        ]);

        // GET /write should be 405 not 404
        try {
            $router->match(Method::GET, '/write');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertSame(405, $e->getCode());
        }
    }

    #[Test]
    public function loadRoutesWithNamedRoutes(): void
    {
        $router = new Router();
        $router->loadRoutes([
            Route::get('/users', fn() => null, 'users.list'),
            Route::get('/users/{id}', fn() => null, 'users.show'),
        ]);

        self::assertNotNull($router->getByName('users.list'));
        self::assertNotNull($router->getByName('users.show'));
        self::assertNull($router->getByName('users.create'));
    }

    // --- group() callback ---

    #[Test]
    public function groupCallbackRegistersRoutes(): void
    {
        $router = new Router();

        $router->group('/api', function (Router $sub) {
            $sub->get('/users', fn() => null, 'api.users');
            $sub->post('/users', fn() => null);
        });

        self::assertCount(2, $router->routes);
        self::assertSame('/api/users', $router->routes[0]->path);
    }

    #[Test]
    public function groupCallbackNestedPrefixes(): void
    {
        $router = new Router();

        $router->group('/api', function (Router $sub) {
            $sub->group('/v1', function (Router $inner) {
                $inner->get('/items', fn() => null);
            });
        });

        self::assertCount(1, $router->routes);
        self::assertSame('/api/v1/items', $router->routes[0]->path);
    }

    #[Test]
    public function groupCallbackReturnsSelf(): void
    {
        $router = new Router();

        $result = $router->group('/api', function (Router $sub) {
            $sub->get('/test', fn() => null);
        });

        self::assertSame($router, $result);
    }

    #[Test]
    public function groupCallbackPreservesRouteAttributes(): void
    {
        $router = new Router();

        $router->group('/admin', function (Router $sub) {
            $sub->add(new Route(
                methods: [Method::GET],
                path: '/dashboard',
                handler: fn() => null,
                name: 'admin.dash',
                middleware: ['auth'],
                constraints: ['id' => '\d+'],
                host: 'admin.example.com',
            ));
        });

        $route = $router->routes[0];
        self::assertSame('/admin/dashboard', $route->path);
        self::assertSame('admin.dash', $route->name);
        self::assertSame(['auth'], $route->middleware);
        self::assertSame(['id' => '\d+'], $route->constraints);
        self::assertSame('admin.example.com', $route->host);
    }

    // --- url() edge cases ---

    #[Test]
    public function urlWithOptionalParameterFilled(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}/posts/{slug?}',
            handler: fn() => null,
            name: 'user.posts',
        ));

        $url = $router->url('user.posts', ['id' => '42', 'slug' => 'hello']);

        self::assertSame('/users/42/posts/hello', $url);
    }

    #[Test]
    public function urlWithOptionalParameterOmitted(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}/posts/{slug?}',
            handler: fn() => null,
            name: 'user.posts',
        ));

        $url = $router->url('user.posts', ['id' => '42']);

        // Optional parameter removed, double slashes cleaned
        self::assertStringNotContainsString('{slug?}', $url);
        self::assertStringNotContainsString('//', $url);
        self::assertSame('/users/42/posts', $url);
    }

    #[Test]
    public function urlWithNoParameters(): void
    {
        $router = new Router();
        $router->get('/about', fn() => null, 'about');

        self::assertSame('/about', $router->url('about'));
    }

    #[Test]
    public function urlThrowsForUnknownRoute(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route "nonexistent" not found');

        $router->url('nonexistent');
    }

    // --- Fluent API ---

    #[Test]
    public function addReturnsSelf(): void
    {
        $router = new Router();

        $result = $router->add(Route::get('/', fn() => null));

        self::assertSame($router, $result);
    }

    #[Test]
    public function getReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->get('/test', fn() => null));
    }

    #[Test]
    public function postReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->post('/test', fn() => null));
    }

    #[Test]
    public function putReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->put('/test', fn() => null));
    }

    #[Test]
    public function patchReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->patch('/test', fn() => null));
    }

    #[Test]
    public function deleteReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->delete('/test', fn() => null));
    }

    #[Test]
    public function anyReturnsSelf(): void
    {
        $router = new Router();

        self::assertSame($router, $router->any('/test', fn() => null));
    }

    // --- Static route O(1) lookup ---

    #[Test]
    public function staticRouteMatchesViaFastPath(): void
    {
        $router = new Router();
        $router->get('/fast', fn() => 'fast-result', 'fast');

        // Static routes without host constraints use O(1) lookup
        $matched = $router->match(Method::GET, '/fast');

        self::assertSame('fast', $matched->getName());
    }

    #[Test]
    public function staticRouteWithTrailingSlashNormalizes(): void
    {
        $router = new Router();
        $router->get('/items', fn() => null);

        $matched = $router->match(Method::GET, '/items/');

        // Path normalization: /items/ -> /items
        self::assertSame([], $matched->parameters);
    }

    // --- routes() accessor ---

    #[Test]
    public function routesReturnsAllRegisteredRoutes(): void
    {
        $router = new Router();
        $router->get('/a', fn() => null);
        $router->post('/b', fn() => null);

        $routes = $router->routes();

        self::assertCount(2, $routes);
        self::assertSame('/a', $routes[0]->path);
        self::assertSame('/b', $routes[1]->path);
    }
}
