<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedBinding;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\CachedRouteTable;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\RouteCache;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Route;
use Pulsar\Security\Crypto\HmacService;

#[CoversClass(RouteCache::class)]
#[CoversClass(RouteHandler::class)]
#[CoversClass(CachedRouteTable::class)]
final class RouteCacheTest extends TestCase
{
    /**
     * The deserialization allowlist a real boot derives from the payload.
     *
     * @var list<class-string>
     */
    private const array ALLOWED = [
        CachedRouteTable::class,
        CachedRoute::class,
        RouteHandler::class,
        RouteHandlerType::class,
        Method::class,
        CachedBinding::class,
        BindingScope::class,
    ];

    private string $hmacKey;
    private CacheIntegrity $integrity;
    private RouteCache $routeCache;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity(new HmacService(), $this->hmacKey);
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
        $route = Route::get('/test', 'App\\Controllers\\HomeController', 'home');

        $result = $this->routeCache->write($this->tempDir, [$route], false, []);

        self::assertSame(1, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->routes);
        self::assertSame(RouteHandlerType::Invokable, $loaded->routes[0]->handler->type);
        self::assertSame('App\\Controllers\\HomeController', $loaded->routes[0]->handler->resolvable);
        self::assertNull($loaded->routes[0]->handler->method);
    }

    #[Test]
    public function arrayHandlerNormalizedToMethod(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/users',
            handler: ['App\\Controllers\\UserController', 'store'],
            name: 'users.store',
        );

        $result = $this->routeCache->write($this->tempDir, [$route], false, []);

        self::assertSame(1, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->routes);
        self::assertSame(RouteHandlerType::Method, $loaded->routes[0]->handler->type);
        self::assertSame('App\\Controllers\\UserController', $loaded->routes[0]->handler->resolvable);
        self::assertSame('store', $loaded->routes[0]->handler->method);
    }

    #[Test]
    public function closureHandlerSkipped(): void
    {
        $route = Route::get('/closure', static function (): string {
            return 'hello';
        });

        $result = $this->routeCache->write($this->tempDir, [$route], false, []);

        self::assertSame(0, $result['cached']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['/closure'], $result['skippedRoutes']);
    }

    #[Test]
    public function writeAndLoadRoundTripForClassBasedRoutes(): void
    {
        $routes = [
            Route::get('/home', 'App\\Controllers\\HomeController', 'home'),
            new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/users/{id}',
                handler: ['App\\Controllers\\UserController', 'show'],
                name: 'users.show',
            ),
            Route::post('/api/data', 'App\\Handlers\\DataHandler', 'api.data'),
        ];

        $result = $this->routeCache->write($this->tempDir, $routes, false, []);

        self::assertSame(3, $result['cached']);
        self::assertSame(0, $result['skipped']);

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertCount(3, $loaded->routes);

        // First route
        self::assertSame('/home', $loaded->routes[0]->path);
        self::assertSame('home', $loaded->routes[0]->name);
        self::assertSame(RouteHandlerType::Invokable, $loaded->routes[0]->handler->type);
        self::assertContains(Method::GET, $loaded->routes[0]->methods);
        self::assertContains(Method::HEAD, $loaded->routes[0]->methods);

        // Second route
        self::assertSame('/users/{id}', $loaded->routes[1]->path);
        self::assertSame('users.show', $loaded->routes[1]->name);
        self::assertSame(RouteHandlerType::Method, $loaded->routes[1]->handler->type);
        self::assertSame('show', $loaded->routes[1]->handler->method);

        // Third route
        self::assertSame('/api/data', $loaded->routes[2]->path);
        self::assertSame('api.data', $loaded->routes[2]->name);
    }

    #[Test]
    public function routeWithConstraintsNameHostSurvivesCache(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/products/{id}/{slug}',
            handler: ['App\\Controllers\\ProductController', 'show'],
            name: 'products.show',
            attributes: ['version' => 2, 'deprecated' => false],
            middleware: ['App\\Middleware\\AuthMiddleware', 'App\\Middleware\\CacheMiddleware'],
            constraints: ['id' => '\\d+', 'slug' => '[a-z0-9-]+'],
            host: 'api.example.com',
        );

        $this->routeCache->write($this->tempDir, [$route], false, []);

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->routes);

        $cached = $loaded->routes[0];
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
            Route::get('/home', 'App\\Controllers\\HomeController', 'home'),
            Route::get('/closure', static fn(): string => 'closure'),
            Route::post('/submit', 'App\\Controllers\\FormController', 'form.submit'),
            Route::get('/another-closure', Closure::fromCallable(static fn(): string => 'another')),
        ];

        $result = $this->routeCache->write($this->tempDir, $routes, false, []);

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

    #[Test]
    public function bindingDeclarationsRoundTripWithTheRoutesTheyQualify(): void
    {
        // The declarations are the half of the route table that used to be left
        // behind. `Router::model()` runs from the project route files, which a
        // cached-route boot skips — so a cache that stored the routes without
        // them served an application whose scope declarations had gone.
        $bindings = [
            new CachedBinding('setting', RouteCacheSetting::class, null, BindingScope::Root, null),
            new CachedBinding('post', RouteCachePost::class, null, BindingScope::Contained, 'posts'),
            new CachedBinding('user', RouteCacheUser::class, RouteCacheUserResolver::class, BindingScope::Path, null),
        ];

        $result = $this->routeCache->write(
            $this->tempDir,
            [Route::get('/users/{user}/settings/{setting}', 'App\\Controllers\\SettingController', 'settings.show')],
            false,
            $bindings,
        );

        self::assertSame(1, $result['cached']);

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->routes);
        self::assertCount(3, $loaded->bindings);

        self::assertSame('setting', $loaded->bindings[0]->parameter);
        self::assertSame(BindingScope::Root, $loaded->bindings[0]->scope);
        self::assertNull($loaded->bindings[0]->parentRelation);

        self::assertSame(BindingScope::Contained, $loaded->bindings[1]->scope);
        self::assertSame('posts', $loaded->bindings[1]->parentRelation);

        // The per-parameter resolver rides on the same declaration and is lost
        // with it, so it is part of the round trip rather than an extra.
        self::assertSame(RouteCacheUserResolver::class, $loaded->bindings[2]->resolverClass);
        self::assertSame(BindingScope::Path, $loaded->bindings[2]->scope);
    }

    #[Test]
    public function anEmptyDeclarationListIsPreservedRatherThanInvented(): void
    {
        $this->routeCache->write(
            $this->tempDir,
            [Route::get('/home', 'App\\Controllers\\HomeController', 'home')],
            false,
            [],
        );

        $loaded = $this->routeCache->load($this->tempDir, self::ALLOWED);

        self::assertNotNull($loaded);
        self::assertSame([], $loaded->bindings);
    }

    #[Test]
    public function aBareRouteListPayloadIsRefusedRatherThanReadAsDeclaringNothing(): void
    {
        // The shape a previous build wrote: `list<CachedRoute>`, with nowhere to
        // put a declaration. Read as-is it is indistinguishable from an
        // application that declares none, which is the whole defect. Refusing it
        // makes the boot cold, and a cold boot reads the route files where the
        // declarations are actually made.
        $legacy = [
            new CachedRoute(
                methods: [Method::GET],
                path: '/home',
                handler: new RouteHandler(RouteHandlerType::Invokable, 'App\\Controllers\\HomeController'),
            ),
        ];

        $this->integrity->writeEnvelope(
            $this->tempDir . DIRECTORY_SEPARATOR . RouteCache::FILENAME,
            serialize($legacy),
            false,
        );

        self::assertNull($this->routeCache->load($this->tempDir, self::ALLOWED));
    }

    #[Test]
    public function aPayloadWhoseClassesFellOutsideTheAllowlistIsRefused(): void
    {
        // unserialize() does not reject a disallowed class — it substitutes
        // __PHP_Incomplete_Class, which satisfies every `array` type hint in the
        // payload and fails much later, somewhere with no cold boot to fall back
        // to. The shape is therefore checked element by element.
        $this->routeCache->write(
            $this->tempDir,
            [Route::get('/home', 'App\\Controllers\\HomeController', 'home')],
            false,
            [new CachedBinding('setting', RouteCacheSetting::class, null, BindingScope::Root, null)],
        );

        $withoutBindingClasses = [
            CachedRouteTable::class,
            CachedRoute::class,
            RouteHandler::class,
            RouteHandlerType::class,
            Method::class,
        ];

        self::assertNull($this->routeCache->load($this->tempDir, $withoutBindingClasses));
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

/**
 * Stand-ins for the entities and the per-parameter resolver a declaration
 * names. Real classes, because ExplicitBinding takes class-strings and a cached
 * declaration that named a class nobody could load would be a different defect.
 *
 * @internal
 */
final class RouteCacheUser {}

/** @internal */
final class RouteCacheSetting {}

/** @internal */
final class RouteCachePost {}

/** @internal */
final class RouteCacheUserResolver {}
