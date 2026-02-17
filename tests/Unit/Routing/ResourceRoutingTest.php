<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
final class ResourceRoutingTest extends TestCase
{
    #[Test]
    public function resourceRegistersSevenRoutes(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        self::assertSame(7, $router->count());
    }

    #[Test]
    public function resourceRegistersCorrectPaths(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $routes = $router->routes();
        $paths = array_map(static fn(Route $r): string => $r->path, $routes);

        self::assertContains('/photos', $paths);
        self::assertContains('/photos/create', $paths);
        self::assertContains('/photos/{photo}', $paths);
        self::assertContains('/photos/{photo}/edit', $paths);
    }

    #[Test]
    public function resourceRegistersCorrectNames(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $names = array_keys($router->namedRoutes);

        self::assertContains('photos.index', $names);
        self::assertContains('photos.create', $names);
        self::assertContains('photos.store', $names);
        self::assertContains('photos.show', $names);
        self::assertContains('photos.edit', $names);
        self::assertContains('photos.update', $names);
        self::assertContains('photos.destroy', $names);
    }

    #[Test]
    public function resourceRegistersCorrectMethods(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $named = $router->namedRoutes;

        // Index: GET, HEAD
        self::assertContains(Method::GET, $named['photos.index']->methods);
        self::assertContains(Method::HEAD, $named['photos.index']->methods);

        // Create: GET, HEAD
        self::assertContains(Method::GET, $named['photos.create']->methods);

        // Store: POST
        self::assertSame([Method::POST], $named['photos.store']->methods);

        // Show: GET, HEAD
        self::assertContains(Method::GET, $named['photos.show']->methods);

        // Edit: GET, HEAD
        self::assertContains(Method::GET, $named['photos.edit']->methods);

        // Update: PUT, PATCH
        self::assertContains(Method::PUT, $named['photos.update']->methods);
        self::assertContains(Method::PATCH, $named['photos.update']->methods);

        // Destroy: DELETE
        self::assertSame([Method::DELETE], $named['photos.destroy']->methods);
    }

    #[Test]
    public function resourceHandlerPairsAreCorrect(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $named = $router->namedRoutes;

        self::assertSame(['App\\Controller\\PhotoController', 'index'], $named['photos.index']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'create'], $named['photos.create']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'store'], $named['photos.store']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'show'], $named['photos.show']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'edit'], $named['photos.edit']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'update'], $named['photos.update']->handler);
        self::assertSame(['App\\Controller\\PhotoController', 'destroy'], $named['photos.destroy']->handler);
    }

    #[Test]
    public function resourceMatchesIndexRoute(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::GET, '/photos');
        self::assertSame('photos.index', $matched->getName());
    }

    #[Test]
    public function resourceMatchesShowRouteWithParameter(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::GET, '/photos/42');
        self::assertSame('photos.show', $matched->getName());
        self::assertSame('42', $matched->parameter('photo'));
    }

    #[Test]
    public function resourceMatchesStoreRoute(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::POST, '/photos');
        self::assertSame('photos.store', $matched->getName());
    }

    #[Test]
    public function resourceMatchesUpdateWithPut(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::PUT, '/photos/1');
        self::assertSame('photos.update', $matched->getName());
        self::assertSame('1', $matched->parameter('photo'));
    }

    #[Test]
    public function resourceMatchesUpdateWithPatch(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::PATCH, '/photos/1');
        self::assertSame('photos.update', $matched->getName());
    }

    #[Test]
    public function resourceMatchesDestroyRoute(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::DELETE, '/photos/1');
        self::assertSame('photos.destroy', $matched->getName());
    }

    #[Test]
    public function resourceMatchesCreateRoute(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::GET, '/photos/create');
        self::assertSame('photos.create', $matched->getName());
    }

    #[Test]
    public function resourceMatchesEditRoute(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        $matched = $router->match(Method::GET, '/photos/5/edit');
        self::assertSame('photos.edit', $matched->getName());
        self::assertSame('5', $matched->parameter('photo'));
    }

    // --- apiResource ---

    #[Test]
    public function apiResourceRegistersFiveRoutes(): void
    {
        $router = new Router();
        $router->apiResource('photos', 'App\\Controller\\PhotoController');

        self::assertSame(5, $router->count());
    }

    #[Test]
    public function apiResourceOmitsCreateAndEditRoutes(): void
    {
        $router = new Router();
        $router->apiResource('photos', 'App\\Controller\\PhotoController');

        $names = array_keys($router->namedRoutes);

        self::assertContains('photos.index', $names);
        self::assertContains('photos.store', $names);
        self::assertContains('photos.show', $names);
        self::assertContains('photos.update', $names);
        self::assertContains('photos.destroy', $names);

        self::assertNotContains('photos.create', $names);
        self::assertNotContains('photos.edit', $names);
    }

    #[Test]
    public function apiResourceMatchesAllFiveRoutes(): void
    {
        $router = new Router();
        $router->apiResource('articles', 'App\\Controller\\ArticleController');

        // Index
        $matched = $router->match(Method::GET, '/articles');
        self::assertSame('articles.index', $matched->getName());

        // Store
        $matched = $router->match(Method::POST, '/articles');
        self::assertSame('articles.store', $matched->getName());

        // Show
        $matched = $router->match(Method::GET, '/articles/99');
        self::assertSame('articles.show', $matched->getName());
        self::assertSame('99', $matched->parameter('article'));

        // Update
        $matched = $router->match(Method::PUT, '/articles/99');
        self::assertSame('articles.update', $matched->getName());

        // Destroy
        $matched = $router->match(Method::DELETE, '/articles/99');
        self::assertSame('articles.destroy', $matched->getName());
    }

    // --- Singularization ---

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function singularizationProvider(): iterable
    {
        yield 'simple plural -s' => ['photos', 'photo'];
        yield '-ies -> -y' => ['categories', 'category'];
        yield '-ses' => ['buses', 'bus'];
        yield '-xes' => ['boxes', 'box'];
        yield '-ches' => ['batches', 'batch'];
        yield '-shes' => ['dishes', 'dish'];
        yield 'already singular' => ['staff', 'staff'];
        yield 'double-s preserved' => ['address', 'address'];
    }

    #[Test]
    #[DataProvider('singularizationProvider')]
    public function resourceSingularizesParameterName(string $plural, string $expectedSingular): void
    {
        $router = new Router();
        $router->apiResource($plural, 'App\\Controller\\TestController');

        $showRoute = $router->namedRoutes[$plural . '.show'];
        $expectedPath = '/' . $plural . '/{' . $expectedSingular . '}';
        self::assertSame($expectedPath, $showRoute->path);
    }

    // --- Middleware ---

    #[Test]
    public function resourceAppliesMiddlewareToAllRoutes(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController', ['auth', 'throttle']);

        foreach ($router->routes() as $route) {
            self::assertSame(['auth', 'throttle'], $route->middleware);
        }
    }

    #[Test]
    public function apiResourceAppliesMiddlewareToAllRoutes(): void
    {
        $router = new Router();
        $router->apiResource('photos', 'App\\Controller\\PhotoController', ['api', 'auth']);

        foreach ($router->routes() as $route) {
            self::assertSame(['api', 'auth'], $route->middleware);
        }
    }

    // --- Locked Router ---

    #[Test]
    public function resourceThrowsWhenRouterIsLocked(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);

        $router->resource('photos', 'App\\Controller\\PhotoController');
    }

    #[Test]
    public function apiResourceThrowsWhenRouterIsLocked(): void
    {
        $router = new Router();
        $router->lock();

        $this->expectException(RoutingException::class);

        $router->apiResource('photos', 'App\\Controller\\PhotoController');
    }

    // --- Multiple Resources ---

    #[Test]
    public function multipleResourcesCanBeRegistered(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');
        $router->resource('comments', 'App\\Controller\\CommentController');

        self::assertSame(14, $router->count());

        $matched = $router->match(Method::GET, '/photos');
        self::assertSame('photos.index', $matched->getName());

        $matched = $router->match(Method::GET, '/comments');
        self::assertSame('comments.index', $matched->getName());
    }

    #[Test]
    public function resourceAndApiResourceCanCoexist(): void
    {
        $router = new Router();
        $router->resource('pages', 'App\\Controller\\PageController');
        $router->apiResource('api/posts', 'App\\Controller\\Api\\PostController');

        // 7 + 5 = 12
        self::assertSame(12, $router->count());
    }

    // --- URL Generation ---

    #[Test]
    public function urlGenerationWorksForResourceRoutes(): void
    {
        $router = new Router();
        $router->resource('photos', 'App\\Controller\\PhotoController');

        self::assertSame('/photos', $router->url('photos.index'));
        self::assertSame('/photos/create', $router->url('photos.create'));
        self::assertSame('/photos/42', $router->url('photos.show', ['photo' => '42']));
        self::assertSame('/photos/42/edit', $router->url('photos.edit', ['photo' => '42']));
    }
}
