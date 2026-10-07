<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteGroup;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

// Purely structural: asserts public-API classes carry the #[Api] attribute via
// reflection, exercising no measurable code. The previous #[CoversClass(Api::class)]
// targeted the Api attribute class, which is not a valid coverage target.
#[CoversNothing]
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
