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
use Pulsar\Routing\RouteCompiler;
use Pulsar\Routing\RoutingException;

#[CoversClass(RouteCompiler::class)]
final class RouteCompilerTest extends TestCase
{
    private RouteCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new RouteCompiler();
    }

    /**
     * @param list<Method>|null $methods
     * @param array<string, string> $constraints
     */
    private function makeRoute(
        string $path,
        string $handler = 'App\\Controller\\HomeController',
        ?string $name = null,
        ?array $methods = null,
        array $constraints = [],
        ?string $host = null,
    ): Route {
        return new Route(
            methods: $methods ?? [Method::GET],
            path: $path,
            handler: self::classString($handler),
            name: $name,
            constraints: $constraints,
            host: $host,
        );
    }

    /**
     * @return class-string
     */
    private static function classString(string $value): string
    {
        /** @var class-string */
        return $value;
    }

    #[Test]
    public function emptyRouteListProducesEmptyTree(): void
    {
        $tree = $this->compiler->compile([]);

        self::assertInstanceOf(CompiledRouteTree::class, $tree);
        self::assertSame(0, $tree->count());
    }

    #[Test]
    public function staticRouteIsIndexedInStaticTable(): void
    {
        $routes = [
            $this->makeRoute('/about', 'App\\Controller\\AboutController', 'about'),
        ];

        $tree = $this->compiler->compile($routes);
        $match = $tree->match(Method::GET, '/about');

        self::assertSame('/about', $match->route->path);
        self::assertSame('App\\Controller\\AboutController', $match->route->handler);
    }

    #[Test]
    public function dynamicRouteMatchesWithParameter(): void
    {
        $routes = [
            $this->makeRoute('/users/{id}', 'App\\Controller\\UserController'),
        ];

        $tree = $this->compiler->compile($routes);
        $match = $tree->match(Method::GET, '/users/42');

        self::assertSame('42', $match->parameters['id']);
    }

    #[Test]
    public function dynamicRouteWithConstraintRejectsInvalidParam(): void
    {
        $routes = [
            $this->makeRoute('/users/{id}', 'App\\Controller\\UserController', constraints: ['id' => '\\d+']),
        ];

        $tree = $this->compiler->compile($routes);

        $this->expectException(RoutingException::class);
        $tree->match(Method::GET, '/users/not-a-number');
    }

    #[Test]
    public function dynamicRouteWithConstraintAcceptsValidParam(): void
    {
        $routes = [
            $this->makeRoute('/users/{id}', 'App\\Controller\\UserController', constraints: ['id' => '\\d+']),
        ];

        $tree = $this->compiler->compile($routes);
        $match = $tree->match(Method::GET, '/users/123');

        self::assertSame('123', $match->parameters['id']);
    }

    #[Test]
    public function namedRoutesAreIndexed(): void
    {
        $routes = [
            $this->makeRoute('/dashboard', 'App\\Controller\\DashboardController', name: 'dashboard'),
        ];

        $tree = $this->compiler->compile($routes);
        $entry = $tree->getByName('dashboard');

        self::assertNotNull($entry);
        self::assertSame('/dashboard', $entry->path);
    }

    #[Test]
    public function unknownNamedRouteReturnsNull(): void
    {
        $tree = $this->compiler->compile([]);

        self::assertNull($tree->getByName('nonexistent'));
    }

    #[Test]
    public function closureHandlersAreSkipped(): void
    {
        $routes = [
            new Route(
                methods: [Method::GET],
                path: '/closure-route',
                handler: static fn() => 'response',
            ),
        ];

        $tree = $this->compiler->compile($routes);

        $this->expectException(RoutingException::class);
        $tree->match(Method::GET, '/closure-route');
    }

    #[Test]
    public function multipleMethodsAreIndexed(): void
    {
        $routes = [
            $this->makeRoute('/api/items', 'App\\Controller\\ItemController', methods: [Method::GET, Method::POST]),
        ];

        $tree = $this->compiler->compile($routes);

        // Both GET and POST should resolve
        $getMatch = $tree->match(Method::GET, '/api/items');
        self::assertSame('/api/items', $getMatch->route->path);

        $postMatch = $tree->match(Method::POST, '/api/items');
        self::assertSame('/api/items', $postMatch->route->path);
    }

    #[Test]
    public function unmatchedMethodThrowsRoutingException(): void
    {
        $routes = [
            $this->makeRoute('/api/items', 'App\\Controller\\ItemController', methods: [Method::GET]),
        ];

        $tree = $this->compiler->compile($routes);

        $this->expectException(RoutingException::class);
        $tree->match(Method::DELETE, '/api/items');
    }

    #[Test]
    public function exportProducesPhpString(): void
    {
        $routes = [
            $this->makeRoute('/home', 'App\\Controller\\HomeController', name: 'home'),
        ];

        $tree = $this->compiler->compile($routes);
        $exported = $this->compiler->export($tree);

        self::assertStringStartsWith('<?php', $exported);
        self::assertStringContainsString('declare(strict_types=1)', $exported);
        self::assertStringContainsString('return ', $exported);
    }

    #[Test]
    public function restoreRoundTripsFromArray(): void
    {
        /** @var array{static: array<string, array<string, array<string, mixed>>>, dynamic: array<string, list<array<string, mixed>>>, named: array<string, array<string, mixed>>} $data */
        $data = [
            'static' => [
                'GET' => [
                    '/static-page' => [
                        'methods' => ['GET'],
                        'path' => '/static-page',
                        'handler' => 'App\\Controller\\PageController',
                        'name' => 'page',
                        'attributes' => [],
                        'middleware' => [],
                        'constraints' => [],
                        'host' => null,
                    ],
                ],
            ],
            'dynamic' => [
                'GET' => [
                    [
                        'pattern' => '#^/posts/(?P<slug>[^/]+)$#',
                        'entry' => [
                            'methods' => ['GET'],
                            'path' => '/posts/{slug}',
                            'handler' => 'App\\Controller\\PostController',
                            'name' => 'post',
                            'attributes' => [],
                            'middleware' => [],
                            'constraints' => [],
                            'host' => null,
                        ],
                        'host' => null,
                        'hostPattern' => null,
                    ],
                ],
            ],
            'named' => [
                'page' => [
                    'methods' => ['GET'],
                    'path' => '/static-page',
                    'handler' => 'App\\Controller\\PageController',
                    'name' => 'page',
                    'attributes' => [],
                    'middleware' => [],
                    'constraints' => [],
                    'host' => null,
                ],
                'post' => [
                    'methods' => ['GET'],
                    'path' => '/posts/{slug}',
                    'handler' => 'App\\Controller\\PostController',
                    'name' => 'post',
                    'attributes' => [],
                    'middleware' => [],
                    'constraints' => [],
                    'host' => null,
                ],
            ],
        ];

        $restored = $this->compiler->restore($data);

        // Static route resolves
        $staticMatch = $restored->match(Method::GET, '/static-page');
        self::assertSame('/static-page', $staticMatch->route->path);

        // Dynamic route resolves
        $dynamicMatch = $restored->match(Method::GET, '/posts/hello-world');
        self::assertSame('hello-world', $dynamicMatch->parameters['slug']);

        // Named routes survive
        self::assertNotNull($restored->getByName('page'));
        self::assertNotNull($restored->getByName('post'));
    }

    #[Test]
    public function arrayHandlerWithClassMethodPairIsAccepted(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/v1/users',
            handler: [self::classString('App\\Controller\\UserApiController'), 'list'],
        );

        $tree = $this->compiler->compile([$route]);
        $match = $tree->match(Method::GET, '/api/v1/users');

        self::assertSame(['App\\Controller\\UserApiController', 'list'], $match->route->handler);
    }

    #[Test]
    public function middlewareAndAttributesArePreserved(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/admin/settings',
            handler: self::classString('App\\Controller\\Admin\\SettingsController'),
            middleware: ['auth', 'admin'],
            attributes: ['section' => 'admin'],
        );

        $tree = $this->compiler->compile([$route]);
        $match = $tree->match(Method::POST, '/admin/settings');

        self::assertSame(['auth', 'admin'], $match->route->middleware);
        self::assertSame(['section' => 'admin'], $match->route->attributes);
    }

    #[Test]
    public function multipleParametersInSingleRoute(): void
    {
        $routes = [
            $this->makeRoute('/blog/{year}/{month}/{slug}', 'App\\Controller\\BlogController'),
        ];

        $tree = $this->compiler->compile($routes);
        $match = $tree->match(Method::GET, '/blog/2026/03/my-post');

        self::assertSame('2026', $match->parameters['year']);
        self::assertSame('03', $match->parameters['month']);
        self::assertSame('my-post', $match->parameters['slug']);
    }

    #[Test]
    public function hostPatternRouteIsCompiledAsDynamic(): void
    {
        $routes = [
            $this->makeRoute('/dashboard', 'App\\Controller\\TenantController', host: '{tenant}.example.com'),
        ];

        $tree = $this->compiler->compile($routes);

        // Route with host pattern goes into dynamic routes, verifiable by count
        self::assertGreaterThan(0, $tree->count());
    }

    #[Test]
    #[DataProvider('trailingSlashNormalizationProvider')]
    public function pathsAreNormalizedWithLeadingSlash(string $inputPath, string $expectedNormalized): void
    {
        $routes = [
            $this->makeRoute($inputPath, 'App\\Controller\\TestController'),
        ];

        $tree = $this->compiler->compile($routes);
        $match = $tree->match(Method::GET, $expectedNormalized);

        self::assertInstanceOf(\Pulsar\Routing\MatchedRoute::class, $match);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trailingSlashNormalizationProvider(): iterable
    {
        yield 'no leading slash' => ['about', '/about'];
        yield 'with leading slash' => ['/about', '/about'];
        yield 'trailing slash stripped' => ['/contact/', '/contact'];
    }
}
