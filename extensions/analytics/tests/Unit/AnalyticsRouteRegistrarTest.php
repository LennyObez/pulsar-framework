<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\AnalyticsRouteRegistrar;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\TrackingConfig;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionRateLimitMiddleware;
use Pulsar\Extension\Analytics\Server\Controller\CollectionController;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Security\Csrf\CsrfMiddleware;

#[CoversClass(AnalyticsRouteRegistrar::class)]
final class AnalyticsRouteRegistrarTest extends TestCase
{
    /**
     * Drives the registrar with a recording router and returns every
     * registered route keyed by its (non-null) route name.
     *
     * @return array<string, Route>
     */
    private function register(?AnalyticsConfig $config = null): array
    {
        /** @var list<Route> $captured */
        $captured = [];

        $router = $this->createStub(RouterInterface::class);
        $router->method('add')->willReturnCallback(
            function (Route $route) use (&$captured, $router): RouterInterface {
                $captured[] = $route;

                return $router;
            },
        );

        new AnalyticsRouteRegistrar()->register($router, $config ?? new AnalyticsConfig(enabled: true));

        $byName = [];
        foreach ($captured as $route) {
            self::assertNotNull($route->name, 'every analytics route must be named');
            self::assertArrayNotHasKey($route->name, $byName, "duplicate route name: {$route->name}");
            $byName[$route->name] = $route;
        }

        return $byName;
    }

    #[Test]
    public function registers_the_complete_route_table(): void
    {
        $routes = $this->register();

        // 2 public + 36 API + 12 dashboard + 2 consent + 2 DSAR.
        self::assertCount(54, $routes);
    }

    #[Test]
    public function public_collection_route_uses_configured_endpoint_and_protective_middleware(): void
    {
        $config = new AnalyticsConfig(
            enabled: true,
            tracking: new TrackingConfig(
                trackerEndpoint: '/custom/collect',
                scriptEndpoint: '/custom/tracker.js',
            ),
        );

        $routes = $this->register($config);

        $collect = $routes['analytics.collect'];
        self::assertSame('/custom/collect', $collect->path);
        self::assertSame([Method::POST], $collect->methods);
        self::assertSame([CollectionController::class, 'collect'], $collect->handler);
        self::assertSame(
            [
                CollectionRateLimitMiddleware::class,
                CollectionCorsMiddleware::class,
                BotFilterMiddleware::class,
            ],
            $collect->middleware,
        );

        $tracker = $routes['analytics.tracker'];
        self::assertSame('/custom/tracker.js', $tracker->path);
        self::assertSame([Method::GET, Method::HEAD], $tracker->methods);
        self::assertSame([], $tracker->middleware, 'tracker script is public, no middleware');
    }

    #[Test]
    public function api_read_routes_require_auth_only(): void
    {
        $routes = $this->register();

        $aggregate = $routes['analytics.api.stats'];
        self::assertSame('/plsr/api/v1/stats/aggregate', $aggregate->path);
        self::assertSame([Method::GET, Method::HEAD], $aggregate->methods);
        self::assertSame([AnalyticsAuthMiddleware::class], $aggregate->middleware);
    }

    #[Test]
    #[DataProvider('mutationRoutes')]
    public function api_mutation_routes_require_auth_and_csrf(string $name, Method $method, string $path): void
    {
        $routes = $this->register();

        $route = $routes[$name];
        self::assertSame($path, $route->path);
        self::assertSame([$method], $route->methods);
        self::assertSame(
            [AnalyticsAuthMiddleware::class, CsrfMiddleware::class],
            $route->middleware,
            "mutation route {$name} must be auth + CSRF protected",
        );
    }

    /**
     * @return iterable<string, array{string, Method, string}>
     */
    public static function mutationRoutes(): iterable
    {
        yield 'goal create' => ['analytics.api.goals.create', Method::POST, '/plsr/api/v1/goals'];
        yield 'goal update' => ['analytics.api.goals.update', Method::PUT, '/plsr/api/v1/goals/{id}'];
        yield 'goal delete' => ['analytics.api.goals.delete', Method::DELETE, '/plsr/api/v1/goals/{id}'];
        yield 'site create' => ['analytics.api.sites.create', Method::POST, '/plsr/api/v1/sites'];
        yield 'segment delete' => ['analytics.api.segments.delete', Method::DELETE, '/plsr/api/v1/segments/{id}'];
        yield 'funnel create' => ['analytics.api.funnels.create', Method::POST, '/plsr/api/v1/funnels'];
    }

    #[Test]
    public function dashboard_pages_cover_every_ga4_section(): void
    {
        $routes = $this->register();

        foreach (['funnels', 'ecommerce', 'events', 'segments', 'attribution', 'flow', 'search'] as $page) {
            $name = "analytics.dashboard.{$page}";
            self::assertArrayHasKey($name, $routes);
            self::assertSame("/analytics/{$page}", $routes[$name]->path);
            self::assertSame([AnalyticsAuthMiddleware::class], $routes[$name]->middleware);
        }
    }

    #[Test]
    public function dashboard_assets_route_is_public(): void
    {
        $routes = $this->register();

        $assets = $routes['analytics.assets'];
        self::assertSame('/analytics/assets/{path}', $assets->path);
        self::assertSame([], $assets->middleware, 'static assets are served without auth');
    }

    #[Test]
    public function consent_routes_are_csrf_protected_without_auth(): void
    {
        $routes = $this->register();

        foreach (['analytics.consent.grant', 'analytics.consent.revoke'] as $name) {
            self::assertSame([Method::POST], $routes[$name]->methods);
            self::assertSame([CsrfMiddleware::class], $routes[$name]->middleware);
        }
    }

    #[Test]
    public function dsar_routes_require_auth_and_csrf(): void
    {
        $routes = $this->register();

        foreach (['analytics.dsar.request', 'analytics.dsar.erase'] as $name) {
            self::assertSame([Method::POST], $routes[$name]->methods);
            self::assertSame(
                [AnalyticsAuthMiddleware::class, CsrfMiddleware::class],
                $routes[$name]->middleware,
            );
        }
    }
}
