<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Http\Method;

#[CoversClass(CachedRouteReconstructor::class)]
final class CachedRouteReconstructorTest extends TestCase
{
    #[Test]
    public function method_handler_becomes_class_method_pair_and_copies_every_field(): void
    {
        $cached = new CachedRoute(
            methods: [Method::GET, Method::HEAD],
            path: '/users/{id}',
            handler: new RouteHandler(RouteHandlerType::Method, 'App\\UserController', 'show'),
            name: 'users.show',
            attributes: ['scope' => 'read'],
            middleware: ['auth'],
            constraints: ['id' => '\d+'],
            host: 'api.example.com',
        );

        $routes = CachedRouteReconstructor::reconstruct([$cached]);

        self::assertCount(1, $routes);
        $route = $routes[0];

        self::assertSame([Method::GET, Method::HEAD], $route->methods);
        self::assertSame('/users/{id}', $route->path);
        self::assertSame(['App\\UserController', 'show'], $route->handler);
        self::assertSame('users.show', $route->name);
        self::assertSame(['scope' => 'read'], $route->attributes);
        self::assertSame(['auth'], $route->middleware);
        self::assertSame(['id' => '\d+'], $route->constraints);
        self::assertSame('api.example.com', $route->host);
    }

    #[Test]
    public function invokable_handler_becomes_the_class_string(): void
    {
        $cached = new CachedRoute(
            methods: [Method::POST],
            path: '/checkout',
            handler: new RouteHandler(RouteHandlerType::Invokable, 'App\\CheckoutAction'),
        );

        $route = CachedRouteReconstructor::reconstruct([$cached])[0];

        self::assertSame('App\\CheckoutAction', $route->handler);
    }

    #[Test]
    public function method_handler_with_null_method_defaults_to_invoke(): void
    {
        $cached = new CachedRoute(
            methods: [Method::GET],
            path: '/',
            handler: new RouteHandler(RouteHandlerType::Method, 'App\\HomeController', null),
        );

        $route = CachedRouteReconstructor::reconstruct([$cached])[0];

        self::assertSame(['App\\HomeController', '__invoke'], $route->handler);
    }

    #[Test]
    public function preserves_order_and_count_across_many_routes(): void
    {
        $cached = [
            new CachedRoute([Method::GET], '/a', new RouteHandler(RouteHandlerType::Invokable, 'A')),
            new CachedRoute([Method::GET], '/b', new RouteHandler(RouteHandlerType::Invokable, 'B')),
            new CachedRoute([Method::GET], '/c', new RouteHandler(RouteHandlerType::Invokable, 'C')),
        ];

        $routes = CachedRouteReconstructor::reconstruct($cached);

        self::assertSame(['/a', '/b', '/c'], [$routes[0]->path, $routes[1]->path, $routes[2]->path]);
    }

    #[Test]
    public function empty_input_yields_empty_output(): void
    {
        self::assertSame([], CachedRouteReconstructor::reconstruct([]));
    }
}
