<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Http\Method;
use ReflectionClass;

#[CoversClass(CachedRoute::class)]
final class CachedRouteTest extends TestCase
{
    #[Test]
    public function constructionWithAllProperties(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controllers\\UserController',
            method: 'show',
        );

        $route = new CachedRoute(
            methods: [Method::GET, Method::HEAD],
            path: '/users/{id}',
            handler: $handler,
            name: 'users.show',
            attributes: ['version' => 1],
            middleware: ['App\\Middleware\\AuthMiddleware'],
            constraints: ['id' => '\\d+'],
            host: 'api.example.com',
        );

        self::assertSame([Method::GET, Method::HEAD], $route->methods);
        self::assertSame('/users/{id}', $route->path);
        self::assertSame($handler, $route->handler);
        self::assertSame('users.show', $route->name);
        self::assertSame(['version' => 1], $route->attributes);
        self::assertSame(['App\\Middleware\\AuthMiddleware'], $route->middleware);
        self::assertSame(['id' => '\\d+'], $route->constraints);
        self::assertSame('api.example.com', $route->host);
    }

    #[Test]
    public function constructionWithDefaults(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Handlers\\HomeHandler',
        );

        $route = new CachedRoute(
            methods: [Method::GET],
            path: '/home',
            handler: $handler,
        );

        self::assertSame([Method::GET], $route->methods);
        self::assertSame('/home', $route->path);
        self::assertSame($handler, $route->handler);
        self::assertNull($route->name);
        self::assertSame([], $route->attributes);
        self::assertSame([], $route->middleware);
        self::assertSame([], $route->constraints);
        self::assertNull($route->host);
    }

    #[Test]
    public function constructionWithInvokableHandler(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Controllers\\DashboardController',
        );

        $route = new CachedRoute(
            methods: [Method::GET, Method::HEAD],
            path: '/dashboard',
            handler: $handler,
            name: 'dashboard',
        );

        self::assertSame(RouteHandlerType::Invokable, $route->handler->type);
        self::assertSame('App\\Controllers\\DashboardController', $route->handler->resolvable);
        self::assertNull($route->handler->method);
    }

    #[Test]
    public function constructionWithMethodHandler(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controllers\\ApiController',
            method: 'list',
        );

        $route = new CachedRoute(
            methods: [Method::GET],
            path: '/api/items',
            handler: $handler,
            name: 'api.items.list',
        );

        self::assertSame(RouteHandlerType::Method, $route->handler->type);
        self::assertSame('App\\Controllers\\ApiController', $route->handler->resolvable);
        self::assertSame('list', $route->handler->method);
    }

    #[Test]
    public function constructionWithMultipleMethods(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Handlers\\AnyHandler',
        );

        $route = new CachedRoute(
            methods: [Method::GET, Method::POST, Method::PUT, Method::DELETE],
            path: '/resource',
            handler: $handler,
        );

        self::assertCount(4, $route->methods);
        self::assertContains(Method::GET, $route->methods);
        self::assertContains(Method::POST, $route->methods);
        self::assertContains(Method::PUT, $route->methods);
        self::assertContains(Method::DELETE, $route->methods);
    }

    #[Test]
    public function constructionWithMultipleMiddleware(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Handlers\\SecureHandler',
        );

        $route = new CachedRoute(
            methods: [Method::POST],
            path: '/secure',
            handler: $handler,
            middleware: [
                'App\\Middleware\\AuthMiddleware',
                'App\\Middleware\\RateLimitMiddleware',
                'App\\Middleware\\CsrfMiddleware',
            ],
        );

        self::assertCount(3, $route->middleware);
        self::assertSame('App\\Middleware\\AuthMiddleware', $route->middleware[0]);
        self::assertSame('App\\Middleware\\RateLimitMiddleware', $route->middleware[1]);
        self::assertSame('App\\Middleware\\CsrfMiddleware', $route->middleware[2]);
    }

    #[Test]
    public function constructionWithMultipleConstraints(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controllers\\ProductController',
            method: 'show',
        );

        $route = new CachedRoute(
            methods: [Method::GET],
            path: '/products/{category}/{id}',
            handler: $handler,
            constraints: [
                'category' => '[a-z]+',
                'id' => '\\d+',
            ],
        );

        self::assertSame(['category' => '[a-z]+', 'id' => '\\d+'], $route->constraints);
    }

    #[Test]
    public function cachedRouteIsReadonly(): void
    {
        $ref = new ReflectionClass(CachedRoute::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function routeHandlerIsReadonly(): void
    {
        $ref = new ReflectionClass(RouteHandler::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function cachedRouteSerializesAndDeserializes(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controllers\\UserController',
            method: 'update',
        );

        $route = new CachedRoute(
            methods: [Method::PUT, Method::PATCH],
            path: '/users/{id}',
            handler: $handler,
            name: 'users.update',
            attributes: ['auth' => true],
            middleware: ['App\\Middleware\\AuthMiddleware'],
            constraints: ['id' => '\\d+'],
            host: '{tenant}.example.com',
        );

        $serialized = serialize($route);
        $deserialized = unserialize($serialized, [
            'allowed_classes' => [CachedRoute::class, RouteHandler::class, RouteHandlerType::class, Method::class],
        ]);

        self::assertInstanceOf(CachedRoute::class, $deserialized);
        self::assertSame($route->path, $deserialized->path);
        self::assertSame($route->name, $deserialized->name);
        self::assertSame($route->host, $deserialized->host);
        self::assertSame($route->constraints, $deserialized->constraints);
        self::assertSame($route->middleware, $deserialized->middleware);
        self::assertSame($route->attributes, $deserialized->attributes);
        self::assertSame($route->handler->type, $deserialized->handler->type);
        self::assertSame($route->handler->resolvable, $deserialized->handler->resolvable);
        self::assertSame($route->handler->method, $deserialized->handler->method);
    }
}
