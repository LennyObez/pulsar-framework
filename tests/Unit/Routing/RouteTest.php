<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function matchesMethodReturnsTrueForMatchingMethod(): void
    {
        $route = new Route([Method::GET, Method::POST], '/', fn() => null);

        self::assertTrue($route->matchesMethod(Method::GET));
        self::assertTrue($route->matchesMethod(Method::POST));
        self::assertFalse($route->matchesMethod(Method::DELETE));
    }

    #[Test]
    public function matchesPathMatchesExactPath(): void
    {
        $route = new Route([Method::GET], '/users', fn() => null);

        self::assertSame([], $route->matchesPath('/users'));
        self::assertSame([], $route->matchesPath('/users/'));
        self::assertNull($route->matchesPath('/users/1'));
        self::assertNull($route->matchesPath('/other'));
    }

    #[Test]
    public function matchesPathMatchesRootPath(): void
    {
        $route = new Route([Method::GET], '/', fn() => null);

        self::assertSame([], $route->matchesPath('/'));
    }

    #[Test]
    public function matchesPathExtractsParameters(): void
    {
        $route = new Route([Method::GET], '/users/{id}', fn() => null);

        $params = $route->matchesPath('/users/123');

        self::assertSame(['id' => '123'], $params);
    }

    #[Test]
    public function matchesPathExtractsMultipleParameters(): void
    {
        $route = new Route([Method::GET], '/users/{userId}/posts/{postId}', fn() => null);

        $params = $route->matchesPath('/users/1/posts/42');

        self::assertSame(['userId' => '1', 'postId' => '42'], $params);
    }

    #[Test]
    public function matchesPathDoesNotMatchParameterWithSlash(): void
    {
        $route = new Route([Method::GET], '/users/{id}', fn() => null);

        self::assertNull($route->matchesPath('/users/1/2'));
    }

    /**
     * @return iterable<string, array{string, list<Method>}>
     */
    public static function httpMethodFactoryProvider(): iterable
    {
        yield 'get' => ['get', [Method::GET, Method::HEAD]];
        yield 'post' => ['post', [Method::POST]];
        yield 'put' => ['put', [Method::PUT]];
        yield 'patch' => ['patch', [Method::PATCH]];
        yield 'delete' => ['delete', [Method::DELETE]];
    }

    /**
     * @param list<Method> $expectedMethods
     */
    #[Test]
    #[DataProvider('httpMethodFactoryProvider')]
    public function factoryCreatesRouteWithCorrectMethod(string $factoryMethod, array $expectedMethods): void
    {
        $handler = fn() => null;
        $route = Route::$factoryMethod('/test', $handler, 'test');
        self::assertInstanceOf(Route::class, $route);

        self::assertSame($expectedMethods, $route->methods);
        self::assertSame('/test', $route->path);
        self::assertSame($handler, $route->handler);
        self::assertSame('test', $route->name);
    }

    #[Test]
    public function anyFactoryCreatesRouteMatchingAllMethods(): void
    {
        $route = Route::any('/test', fn() => null);

        self::assertContains(Method::GET, $route->methods);
        self::assertContains(Method::POST, $route->methods);
        self::assertContains(Method::PUT, $route->methods);
        self::assertContains(Method::PATCH, $route->methods);
        self::assertContains(Method::DELETE, $route->methods);
        self::assertContains(Method::OPTIONS, $route->methods);
    }

    // --- Route Constraints ---

    #[Test]
    public function constraintEnforcesDigitsOnly(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: fn() => null,
            constraints: ['id' => '\d+'],
        );

        self::assertSame(['id' => '123'], $route->matchesPath('/users/123'));
        self::assertNull($route->matchesPath('/users/abc'));
        self::assertNull($route->matchesPath('/users/12a'));
    }

    #[Test]
    public function constraintEnforcesAlphaSlug(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/posts/{slug}',
            handler: fn() => null,
            constraints: ['slug' => '[a-z0-9\-]+'],
        );

        self::assertSame(['slug' => 'hello-world'], $route->matchesPath('/posts/hello-world'));
        self::assertNull($route->matchesPath('/posts/Hello_World'));
    }

    #[Test]
    public function multipleConstraintsOnDifferentParameters(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}/posts/{slug}',
            handler: fn() => null,
            constraints: ['id' => '\d+', 'slug' => '[a-z\-]+'],
        );

        self::assertSame(
            ['id' => '42', 'slug' => 'my-post'],
            $route->matchesPath('/users/42/posts/my-post'),
        );
        self::assertNull($route->matchesPath('/users/abc/posts/my-post'));
        self::assertNull($route->matchesPath('/users/42/posts/MY_POST'));
    }

    #[Test]
    public function unconstrainedParameterStillMatchesAnything(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}/posts/{slug}',
            handler: fn() => null,
            constraints: ['id' => '\d+'],
        );

        // id is constrained but slug is not
        self::assertSame(
            ['id' => '42', 'slug' => 'Anything_123'],
            $route->matchesPath('/users/42/posts/Anything_123'),
        );
    }

    // --- Host-Based Routing ---

    #[Test]
    public function matchesHostExactMatch(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => null,
            host: 'api.example.com',
        );

        self::assertSame([], $route->matchesHost('api.example.com'));
        self::assertSame([], $route->matchesHost('API.EXAMPLE.COM'));
        self::assertNull($route->matchesHost('www.example.com'));
    }

    #[Test]
    public function matchesHostWithParameter(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => null,
            host: '{subdomain}.example.com',
        );

        $params = $route->matchesHost('api.example.com');

        self::assertSame(['subdomain' => 'api'], $params);
    }

    #[Test]
    public function matchesHostReturnsNullForNonMatch(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => null,
            host: '{subdomain}.example.com',
        );

        self::assertNull($route->matchesHost('api.other.com'));
    }

    #[Test]
    public function matchesHostWithNoHostPatternMatchesAny(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/',
            handler: fn() => null,
        );

        self::assertSame([], $route->matchesHost('anything.example.com'));
    }

    #[Test]
    public function constructorSetsConstraintsAndHost(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: fn() => null,
            constraints: ['id' => '\d+'],
            host: 'api.example.com',
        );

        self::assertSame(['id' => '\d+'], $route->constraints);
        self::assertSame('api.example.com', $route->host);
    }

    // --- compiledPattern pre-compilation ---

    #[Test]
    public function compiledPatternIsNullForStaticRoute(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users',
            handler: fn() => null,
        );

        self::assertNull($route->compiledPattern);
    }

    #[Test]
    public function compiledPatternIsSetForParameterizedRoute(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: fn() => null,
        );

        self::assertNotNull($route->compiledPattern);
        self::assertSame(1, preg_match($route->compiledPattern, '/users/123'));
        self::assertSame(0, preg_match($route->compiledPattern, '/posts/123'));
    }

    #[Test]
    public function compiledPatternRespectsConstraints(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: fn() => null,
            constraints: ['id' => '\d+'],
        );

        self::assertNotNull($route->compiledPattern);
        self::assertSame(1, preg_match($route->compiledPattern, '/users/123'));
        self::assertSame(0, preg_match($route->compiledPattern, '/users/abc'));
    }
}
