<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\RouteCache;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

#[CoversClass(RouteCache::class)]
#[CoversClass(RouteHandler::class)]
#[CoversClass(RouteHandlerType::class)]
final class RouteCacheTest extends TestCase
{
    private string $hmacKey;
    private CacheIntegrity $integrity;
    private RouteCache $routeCache;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity($this->hmacKey);
        $this->routeCache = new RouteCache($this->integrity);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_route_cache_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function stringHandlerNormalizedToInvokable(): void
    {
        /** @phpstan-ignore argument.type */
        $route = Route::get('/test', 'App\\Controllers\\HomeController', 'home');

        $result = $this->routeCache->write($this->tempDir, [$route], false);

        self::assertSame(1, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, [
            CachedRoute::class,
            RouteHandler::class,
            RouteHandlerType::class,
            Method::class,
        ]);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded);
        self::assertSame(RouteHandlerType::Invokable, $loaded[0]->handler->type);
        self::assertSame('App\\Controllers\\HomeController', $loaded[0]->handler->resolvable);
        self::assertNull($loaded[0]->handler->method);
    }

    #[Test]
    public function arrayHandlerNormalizedToMethod(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/users',
            handler: ['App\\Controllers\\UserController', 'store'], // @phpstan-ignore argument.type
            name: 'users.store',
        );

        $result = $this->routeCache->write($this->tempDir, [$route], false);

        self::assertSame(1, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, [
            CachedRoute::class,
            RouteHandler::class,
            RouteHandlerType::class,
            Method::class,
        ]);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded);
        self::assertSame(RouteHandlerType::Method, $loaded[0]->handler->type);
        self::assertSame('App\\Controllers\\UserController', $loaded[0]->handler->resolvable);
        self::assertSame('store', $loaded[0]->handler->method);
    }

    #[Test]
    public function closureHandlerSkipped(): void
    {
        $route = Route::get('/closure', static function (): string {
            return 'hello';
        });

        $result = $this->routeCache->write($this->tempDir, [$route], false);

        self::assertSame(0, $result['cached']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['/closure'], $result['skippedRoutes']);
    }

    #[Test]
    public function writeAndLoadRoundTripForClassBasedRoutes(): void
    {
        $routes = [
            Route::get('/home', 'App\\Controllers\\HomeController', 'home'), // @phpstan-ignore argument.type
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/users/{id}',
                handler: ['App\\Controllers\\UserController', 'show'], // @phpstan-ignore argument.type
                name: 'users.show',
            ),
            Route::post('/api/data', 'App\\Handlers\\DataHandler', 'api.data'), // @phpstan-ignore argument.type
        ];

        $result = $this->routeCache->write($this->tempDir, $routes, false);

        self::assertSame(3, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, [
            CachedRoute::class,
            RouteHandler::class,
            RouteHandlerType::class,
            Method::class,
        ]);

        self::assertNotNull($loaded);
        self::assertCount(3, $loaded);

        // First route
        self::assertSame('/home', $loaded[0]->path);
        self::assertSame('home', $loaded[0]->name);
        self::assertSame(RouteHandlerType::Invokable, $loaded[0]->handler->type);
        self::assertContains(Method::GET, $loaded[0]->methods);
        self::assertContains(Method::HEAD, $loaded[0]->methods);

        // Second route
        self::assertSame('/users/{id}', $loaded[1]->path);
        self::assertSame('users.show', $loaded[1]->name);
        self::assertSame(RouteHandlerType::Method, $loaded[1]->handler->type);
        self::assertSame('show', $loaded[1]->handler->method);

        // Third route
        self::assertSame('/api/data', $loaded[2]->path);
        self::assertSame('api.data', $loaded[2]->name);
    }

    #[Test]
    public function routeWithConstraintsNameHostSurvivesCache(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/products/{id}/{slug}',
            handler: ['App\\Controllers\\ProductController', 'show'], // @phpstan-ignore argument.type
            name: 'products.show',
            attributes: ['version' => 2, 'deprecated' => false],
            middleware: ['App\\Middleware\\AuthMiddleware', 'App\\Middleware\\CacheMiddleware'],
            constraints: ['id' => '\\d+', 'slug' => '[a-z0-9-]+'],
            host: 'api.example.com',
        );

        $this->routeCache->write($this->tempDir, [$route], false);

        $loaded = $this->routeCache->load($this->tempDir, [
            CachedRoute::class,
            RouteHandler::class,
            RouteHandlerType::class,
            Method::class,
        ]);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded);

        $cached = $loaded[0];
        self::assertSame('/products/{id}/{slug}', $cached->path);
        self::assertSame('products.show', $cached->name);
        self::assertSame('api.example.com', $cached->host);
        self::assertSame(['id' => '\\d+', 'slug' => '[a-z0-9-]+'], $cached->constraints);
        self::assertSame(['App\\Middleware\\AuthMiddleware', 'App\\Middleware\\CacheMiddleware'], $cached->middleware);
        self::assertSame(['version' => 2, 'deprecated' => false], $cached->attributes);
        self::assertContains(Method::GET, $cached->methods);
        self::assertContains(Method::HEAD, $cached->methods);
    }

    #[Test]
    public function routeHandlerTypesSerializeDeserializeCorrectly(): void
    {
        // Test Invokable type
        $invokableHandler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Handlers\\InvokableHandler',
        );

        $serialized = serialize($invokableHandler);
        $deserialized = unserialize($serialized, ['allowed_classes' => [RouteHandler::class, RouteHandlerType::class]]);

        self::assertInstanceOf(RouteHandler::class, $deserialized);
        self::assertSame(RouteHandlerType::Invokable, $deserialized->type);
        self::assertSame('App\\Handlers\\InvokableHandler', $deserialized->resolvable);
        self::assertNull($deserialized->method);

        // Test Method type
        $methodHandler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controllers\\UserController',
            method: 'index',
        );

        $serialized = serialize($methodHandler);
        $deserialized = unserialize($serialized, ['allowed_classes' => [RouteHandler::class, RouteHandlerType::class]]);

        self::assertInstanceOf(RouteHandler::class, $deserialized);
        self::assertSame(RouteHandlerType::Method, $deserialized->type);
        self::assertSame('App\\Controllers\\UserController', $deserialized->resolvable);
        self::assertSame('index', $deserialized->method);
    }

    #[Test]
    public function mixedClosureAndClassRoutesPartiallyCache(): void
    {
        $routes = [
            Route::get('/home', 'App\\Controllers\\HomeController', 'home'), // @phpstan-ignore argument.type
            Route::get('/closure', static fn(): string => 'closure'),
            Route::post('/submit', 'App\\Controllers\\FormController', 'form.submit'), // @phpstan-ignore argument.type
            Route::get('/another-closure', Closure::fromCallable(static fn(): string => 'another')),
        ];

        $result = $this->routeCache->write($this->tempDir, $routes, false);

        self::assertSame(2, $result['cached']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(['/closure', '/another-closure'], $result['skippedRoutes']);
    }

    #[Test]
    public function loadReturnsNullForMissingCacheFile(): void
    {
        $emptyDir = $this->tempDir . DIRECTORY_SEPARATOR . 'empty';
        mkdir($emptyDir, 0o750, true);

        $loaded = $this->routeCache->load($emptyDir, [CachedRoute::class]);

        self::assertNull($loaded);
    }

    #[Test]
    public function routeHandlerTypeEnumValues(): void
    {
        self::assertSame('invokable', RouteHandlerType::Invokable->value);
        self::assertSame('method', RouteHandlerType::Method->value);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
