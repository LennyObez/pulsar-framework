<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

#[CoversClass(MatchedRoute::class)]
final class MatchedRouteTest extends TestCase
{
    #[Test]
    public function constructionStoresRouteAndParameters(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: 'UserController',
            name: 'user.show',
            middleware: ['auth'],
            attributes: ['section' => 'api'],
        );

        $match = new MatchedRoute($route, ['id' => '42']);

        self::assertSame($route, $match->route);
        self::assertSame(['id' => '42'], $match->parameters);
    }

    #[Test]
    public function parametersDefaultToEmptyArray(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/home',
            handler: 'HomeController',
        );

        $match = new MatchedRoute($route);

        self::assertSame([], $match->parameters);
    }

    #[Test]
    public function parameterReturnsValueWhenPresent(): void
    {
        $route = new Route([Method::GET], '/posts/{slug}', 'PostController');
        $match = new MatchedRoute($route, ['slug' => 'hello-world']);

        self::assertSame('hello-world', $match->parameter('slug'));
    }

    #[Test]
    public function parameterReturnsDefaultWhenMissing(): void
    {
        $route = new Route([Method::GET], '/posts/{slug}', 'PostController');
        $match = new MatchedRoute($route, ['slug' => 'hello']);

        self::assertNull($match->parameter('missing'));
        self::assertSame('fallback', $match->parameter('missing', 'fallback'));
    }

    #[Test]
    public function hasParameterReturnsTrueForExistingParameter(): void
    {
        $route = new Route([Method::GET], '/items/{id}', 'ItemController');
        $match = new MatchedRoute($route, ['id' => '99']);

        self::assertTrue($match->hasParameter('id'));
        self::assertFalse($match->hasParameter('slug'));
    }

    #[Test]
    public function getHandlerDelegatesToRoute(): void
    {
        $route = new Route([Method::POST], '/api/data', 'DataController');
        $match = new MatchedRoute($route);

        self::assertSame('DataController', $match->getHandler());
    }

    #[Test]
    public function getNameDelegatesToRoute(): void
    {
        $route = new Route([Method::GET], '/dashboard', 'DashController', name: 'dashboard');
        $match = new MatchedRoute($route);

        self::assertSame('dashboard', $match->getName());
    }

    #[Test]
    public function getNameReturnsNullWhenUnnamed(): void
    {
        $route = new Route([Method::GET], '/dashboard', 'DashController');
        $match = new MatchedRoute($route);

        self::assertNull($match->getName());
    }

    #[Test]
    public function getMiddlewareDelegatesToRoute(): void
    {
        $route = new Route([Method::GET], '/admin', 'AdminController', middleware: ['auth', 'admin']);
        $match = new MatchedRoute($route);

        self::assertSame(['auth', 'admin'], $match->getMiddleware());
    }

    #[Test]
    public function getAttributesDelegatesToRoute(): void
    {
        $route = new Route([Method::GET], '/api/v2', 'ApiController', attributes: ['version' => 2]);
        $match = new MatchedRoute($route);

        self::assertSame(['version' => 2], $match->getAttributes());
    }
}
