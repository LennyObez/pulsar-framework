<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteGroup;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

#[CoversClass(Api::class)]
final class RoutingApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function routerIsPublicApi(): void
    {
        self::assertHasApiAttribute(Router::class);
    }

    #[Test]
    public function routeIsPublicApi(): void
    {
        self::assertHasApiAttribute(Route::class);
        self::assertClassIsReadonly(Route::class);
    }

    #[Test]
    public function matchedRouteIsPublicApi(): void
    {
        self::assertHasApiAttribute(MatchedRoute::class);
        self::assertClassIsReadonly(MatchedRoute::class);
    }

    #[Test]
    public function routeGroupIsPublicApi(): void
    {
        self::assertHasApiAttribute(RouteGroup::class);
    }

    #[Test]
    public function routingExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(RoutingException::class);
    }

    #[Test]
    public function routingExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(RoutingException::class, 'notFound');
        self::assertStaticFactoryExists(RoutingException::class, 'methodNotAllowed');
    }
}
