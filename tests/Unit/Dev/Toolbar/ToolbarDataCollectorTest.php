<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Toolbar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Toolbar\ToolbarDataCollector;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

#[CoversClass(ToolbarDataCollector::class)]
final class ToolbarDataCollectorTest extends TestCase
{
    #[Test]
    public function collectReturnsToolbarDataWithRequestTime(): void
    {
        $collector = new ToolbarDataCollector();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 42.5);

        self::assertSame(42.5, $data->requestTimeMs);
    }

    #[Test]
    public function recordedQueriesAppearInCollectedData(): void
    {
        $collector = new ToolbarDataCollector();
        $collector->recordQuery('SELECT 1', 1.5);
        $collector->recordQuery('SELECT 2', 2.5);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 10.0);

        self::assertCount(2, $data->queries);
        self::assertSame('SELECT 1', $data->queries[0]['sql']);
        self::assertSame(1.5, $data->queries[0]['time_ms']);
        self::assertSame('SELECT 2', $data->queries[1]['sql']);
    }

    #[Test]
    public function recordedCacheStatsAppearInCollectedData(): void
    {
        $collector = new ToolbarDataCollector();
        $collector->recordCacheHit();
        $collector->recordCacheHit();
        $collector->recordCacheHit();
        $collector->recordCacheMiss();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 10.0);

        self::assertSame(3, $data->cacheHits);
        self::assertSame(1, $data->cacheMisses);
    }

    #[Test]
    public function recordedTemplatesAppearInCollectedData(): void
    {
        $collector = new ToolbarDataCollector();
        $collector->recordTemplate('layout.pulse');
        $collector->recordTemplate('home.pulse');

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 10.0);

        self::assertSame(['layout.pulse', 'home.pulse'], $data->loadedTemplates);
    }

    #[Test]
    public function collectExtractsRouteInfoFromMatchedRoute(): void
    {
        $collector = new ToolbarDataCollector();

        $route = new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: 'UserController::show',
            name: 'users.show',
        );
        $matched = new MatchedRoute($route, ['id' => '42']);

        $request = new ServerRequest(method: 'GET', uri: '/users/42')
            ->withAttribute('_matched_route', $matched);

        $data = $collector->collect($request, 10.0);

        self::assertSame('users.show', $data->routeName);
        self::assertSame('UserController::show', $data->controller);
        self::assertSame('/users/{id}', $data->routePattern);
    }

    #[Test]
    public function collectReturnsNullRouteInfoWhenNoMatchedRoute(): void
    {
        $collector = new ToolbarDataCollector();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 10.0);

        self::assertNull($data->routeName);
        self::assertNull($data->controller);
        self::assertNull($data->routePattern);
    }

    #[Test]
    public function collectExtractsArrayHandlerAsController(): void
    {
        $collector = new ToolbarDataCollector();

        $route = new Route(
            methods: [Method::POST],
            path: '/api/data',
            handler: ['ApiController', 'store'],
        );
        $matched = new MatchedRoute($route);

        $request = new ServerRequest(method: 'POST', uri: '/api/data')
            ->withAttribute('_matched_route', $matched);

        $data = $collector->collect($request, 10.0);

        self::assertSame('ApiController::store', $data->controller);
    }

    #[Test]
    public function resetClearsAllCollectedData(): void
    {
        $collector = new ToolbarDataCollector();
        $collector->recordQuery('SELECT 1', 1.0);
        $collector->recordCacheHit();
        $collector->recordCacheMiss();
        $collector->recordTemplate('test.pulse');

        $collector->reset();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 0.0);

        self::assertSame([], $data->queries);
        self::assertSame(0, $data->cacheHits);
        self::assertSame(0, $data->cacheMisses);
        self::assertSame([], $data->loadedTemplates);
    }

    #[Test]
    public function collectIncludesPhpVersion(): void
    {
        $collector = new ToolbarDataCollector();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 0.0);

        self::assertSame(PHP_VERSION, $data->phpVersion);
    }

    #[Test]
    public function collectIncludesMemoryPeak(): void
    {
        $collector = new ToolbarDataCollector();

        $request = new ServerRequest(method: 'GET', uri: '/');
        $data = $collector->collect($request, 0.0);

        self::assertGreaterThan(0, $data->memoryPeakBytes);
    }
}
