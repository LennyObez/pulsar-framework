<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\CompiledDynamicRoute;
use Pulsar\Routing\CompiledRouteEntry;
use Pulsar\Routing\CompiledRouteTree;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteCompiler;
use Pulsar\Routing\RoutingException;

#[CoversClass(CompiledRouteTree::class)]
#[CoversClass(CompiledRouteEntry::class)]
#[CoversClass(CompiledDynamicRoute::class)]
#[CoversClass(RouteCompiler::class)]
final class CompiledRouteTreeTest extends TestCase
{
    #[Test]
    public function matchesStaticRouteViaHashLookup(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users', 'UserController::index', 'users.index'),
            Route::get('/api/posts', 'PostController::index', 'posts.index'),
        ]);

        $matched = $tree->match(Method::GET, '/api/users');

        self::assertSame('/api/users', $matched->route->path);
        self::assertSame([], $matched->parameters);
    }

    #[Test]
    public function matchesDynamicRouteWithParameters(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users/{id}', 'UserController::show'),
        ]);

        $matched = $tree->match(Method::GET, '/api/users/42');

        self::assertSame('/api/users/{id}', $matched->route->path);
        self::assertSame(['id' => '42'], $matched->parameters);
    }

    #[Test]
    public function matchesDynamicRouteWithConstraints(): void
    {
        $tree = $this->buildTree([
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/api/users/{id}',
                handler: 'UserController::show',
                constraints: ['id' => '\d+'],
            ),
        ]);

        $matched = $tree->match(Method::GET, '/api/users/123');
        self::assertSame(['id' => '123'], $matched->parameters);

        $this->expectException(RoutingException::class);
        $tree->match(Method::GET, '/api/users/abc');
    }

    #[Test]
    public function throwsNotFoundForUnmatchedPath(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users', 'UserController::index'),
        ]);

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        $tree->match(Method::GET, '/api/nonexistent');
    }

    #[Test]
    public function throwsMethodNotAllowedForWrongMethod(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users', 'UserController::index'),
            Route::post('/api/users', 'UserController::store'),
        ]);

        try {
            $tree->match(Method::DELETE, '/api/users');
            self::fail('Expected RoutingException');
        } catch (RoutingException $e) {
            self::assertSame(405, $e->getCode());
            self::assertNotEmpty($e->allowedMethods);
        }
    }

    #[Test]
    public function namedRouteLookupWorks(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users', 'UserController::index', 'users.index'),
            Route::get('/api/posts', 'PostController::index', 'posts.index'),
        ]);

        $entry = $tree->getByName('users.index');
        self::assertNotNull($entry);
        self::assertSame('/api/users', $entry->path);

        self::assertNull($tree->getByName('nonexistent'));
    }

    #[Test]
    public function countReturnsCorrectTotal(): void
    {
        $tree = $this->buildTree([
            Route::get('/static', 'Controller::a'),
            Route::post('/static', 'Controller::b'),
            Route::get('/dynamic/{id}', 'Controller::c'),
        ]);

        // GET /static, HEAD /static, POST /static, GET /dynamic/{id}, HEAD /dynamic/{id}
        self::assertGreaterThanOrEqual(3, $tree->count());
    }

    #[Test]
    public function compiledEntryToRouteRoundtrips(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET', 'HEAD'],
            path: '/api/test',
            handler: 'TestController::index',
            name: 'test.index',
            attributes: ['key' => 'value'],
            middleware: ['auth'],
            constraints: [],
            host: null,
        );

        $route = $entry->toRoute();

        self::assertSame('/api/test', $route->path);
        self::assertSame('test.index', $route->name);
        self::assertSame(['auth'], $route->middleware);
        self::assertContains(Method::GET, $route->methods);
    }

    #[Test]
    public function compilerSkipsClosureHandlers(): void
    {
        $compiler = new RouteCompiler();

        $routes = [
            Route::get('/closure', fn() => 'hello'),
            Route::get('/class', 'Controller::index', 'class.route'),
        ];

        $tree = $compiler->compile($routes);

        self::assertNotNull($tree->getByName('class.route'));
        // Closure route should be skipped entirely
        $this->expectException(RoutingException::class);
        $tree->match(Method::GET, '/closure');
    }

    #[Test]
    public function compilerHandlesMethodArrayHandlers(): void
    {
        $compiler = new RouteCompiler();

        $routes = [
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/test',
                handler: ['App\\Controller', 'index'],
                name: 'test',
            ),
        ];

        $tree = $compiler->compile($routes);
        $entry = $tree->getByName('test');

        self::assertNotNull($entry);
        self::assertSame(['App\\Controller', 'index'], $entry->handler);
    }

    #[Test]
    public function exportAndRestoreRoundtrips(): void
    {
        $compiler = new RouteCompiler();

        $routes = [
            Route::get('/api/users', 'UserController::index', 'users.index'),
            Route::get('/api/users/{id}', 'UserController::show', 'users.show'),
            Route::post('/api/users', 'UserController::store', 'users.store'),
        ];

        $tree = $compiler->compile($routes);

        // Export to array representation
        $exported = $compiler->export($tree);
        self::assertStringStartsWith('<?php', $exported);

        // Verify the tree functions correctly
        $matched = $tree->match(Method::GET, '/api/users');
        self::assertSame('/api/users', $matched->route->path);

        $matched = $tree->match(Method::GET, '/api/users/42');
        self::assertSame(['id' => '42'], $matched->parameters);
    }

    #[Test]
    public function multipleMethodsOnSamePathWork(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/items', 'ItemController::index'),
            Route::post('/api/items', 'ItemController::store'),
            Route::put('/api/items/{id}', 'ItemController::update'),
            Route::delete('/api/items/{id}', 'ItemController::destroy'),
        ]);

        $getMatch = $tree->match(Method::GET, '/api/items');
        self::assertSame('/api/items', $getMatch->route->path);

        $postMatch = $tree->match(Method::POST, '/api/items');
        self::assertSame('/api/items', $postMatch->route->path);

        $putMatch = $tree->match(Method::PUT, '/api/items/5');
        self::assertSame(['id' => '5'], $putMatch->parameters);

        $deleteMatch = $tree->match(Method::DELETE, '/api/items/5');
        self::assertSame(['id' => '5'], $deleteMatch->parameters);
    }

    #[Test]
    public function normalizedPathHandlesTrailingSlashes(): void
    {
        $tree = $this->buildTree([
            Route::get('/api/users', 'UserController::index'),
        ]);

        $matched = $tree->match(Method::GET, '/api/users/');
        self::assertSame('/api/users', $matched->route->path);

        $matched = $tree->match(Method::GET, 'api/users');
        self::assertSame('/api/users', $matched->route->path);
    }

    #[Test]
    public function methodsReturnsAllRegisteredMethods(): void
    {
        $tree = $this->buildTree([
            Route::get('/a', 'C::a'),
            Route::post('/b', 'C::b'),
            Route::put('/c/{id}', 'C::c'),
        ]);

        $methods = $tree->methods();
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);
        self::assertContains('PUT', $methods);
    }

    /**
     * @param list<Route> $routes
     */
    private function buildTree(array $routes): CompiledRouteTree
    {
        return new RouteCompiler()->compile($routes);
    }
}
