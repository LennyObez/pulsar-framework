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
    public function constructorAddsHeadToAnExplicitGetRoute(): void
    {
        // RFC 9110 §9.3.2: HEAD must be supported wherever GET is. The sugar
        // factory always bundled the pair; the explicit constructor (the style
        // used to attach middleware) silently produced 405 on HEAD.
        $route = new Route(methods: [Method::GET], path: '/contact', handler: fn() => null, middleware: ['web']);

        self::assertSame([Method::GET, Method::HEAD], $route->methods);
        self::assertTrue($route->matchesMethod(Method::HEAD));
    }

    #[Test]
    public function constructorDoesNotDuplicateAnExplicitHead(): void
    {
        $route = new Route([Method::GET, Method::HEAD], '/', fn() => null);

        self::assertSame([Method::GET, Method::HEAD], $route->methods);
    }

    #[Test]
    public function constructorLeavesNonGetRoutesUntouched(): void
    {
        // POST-only (and any GET-less) routes must keep answering 405 on HEAD.
        $post = new Route([Method::POST], '/submit', fn() => null);
        $put = new Route([Method::PUT, Method::DELETE], '/resource', fn() => null);

        self::assertSame([Method::POST], $post->methods);
        self::assertFalse($post->matchesMethod(Method::HEAD));
        self::assertSame([Method::PUT, Method::DELETE], $put->methods);
        self::assertFalse($put->matchesMethod(Method::HEAD));
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

    #[Test]
    public function matchesPathAcceptsACustomBindingKeyAndCapturesTheBareName(): void
    {
        // `{user:slug}` declares the column model binding looks the parameter
        // up by. The URL still carries the value alone, and the parameter is
        // captured as `user` — a PCRE group name cannot contain `:`, and the
        // controller signature names the parameter, never the key.
        $route = new Route([Method::GET], '/users/{user:slug}', fn() => null);

        self::assertSame(['user' => 'john-doe'], $route->matchesPath('/users/john-doe'));

        // The key changes nothing about the segment the parameter captures.
        self::assertNull($route->matchesPath('/users/john/doe'));
        self::assertNull($route->matchesPath('/users'));
    }

    #[Test]
    public function matchesPathAcceptsACustomBindingKeyOnANestedRoute(): void
    {
        $route = new Route([Method::GET], '/users/{user:slug}/posts/{post:uuid}', fn() => null);

        self::assertSame(
            ['user' => 'john-doe', 'post' => 'e5f1'],
            $route->matchesPath('/users/john-doe/posts/e5f1'),
        );
    }

    #[Test]
    public function aConstraintAppliesToAParameterDeclaredWithACustomKey(): void
    {
        // Constraints are keyed by parameter name, which the `:key` suffix does
        // not change.
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{user:slug}',
            handler: fn() => null,
            constraints: ['user' => '[a-z\-]+'],
        );

        self::assertSame(['user' => 'john-doe'], $route->matchesPath('/users/john-doe'));
        self::assertNull($route->matchesPath('/users/John_Doe'));
    }

    #[Test]
    public function anOptionalParameterMayAlsoDeclareACustomKey(): void
    {
        $route = new Route([Method::GET], '/posts/{post:slug?}', fn() => null);

        self::assertSame([], $route->matchesPath('/posts'));
        self::assertSame(['post' => 'hello-world'], $route->matchesPath('/posts/hello-world'));
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
