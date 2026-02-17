<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadRoutesTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\RouteEntry;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadRoutesTool::class)]
final class ReadRoutesToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarRoutesList(): void
    {
        self::assertSame('pulsar.routes.list', $this->createTool([])->name());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool([])->category());
    }

    #[Test]
    public function executeReturnsAllRoutesWhenNoFilter(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/api/v1/users', 'UserController@index'),
            new RouteEntry(['POST'], '/api/v1/users', 'UserController@store'),
            new RouteEntry(['GET'], '/admin/dashboard', 'AdminController@index'),
        ];
        $result = $this->createTool($routes)->execute([]);

        self::assertFalse($result->isError);
        self::assertSame(3, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executeFiltersByMethod(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/api/v1/users', 'UserController@index'),
            new RouteEntry(['POST'], '/api/v1/users', 'UserController@store'),
            new RouteEntry(['GET'], '/admin/dashboard', 'AdminController@index'),
        ];
        $result = $this->createTool($routes)->execute(['method' => 'POST']);

        self::assertSame(1, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executeFiltersByMethodCaseInsensitive(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/users', 'Ctrl@index'),
            new RouteEntry(['POST'], '/users', 'Ctrl@store'),
        ];
        self::assertSame(1, $this->createTool($routes)->execute(['method' => 'get'])->structuredContent['totalCount']);
    }

    #[Test]
    public function executeFiltersByPathSubstring(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/api/v1/users', 'Ctrl@index'),
            new RouteEntry(['GET'], '/api/v1/posts', 'Ctrl@index'),
            new RouteEntry(['GET'], '/admin/dashboard', 'Ctrl@index'),
        ];
        self::assertSame(2, $this->createTool($routes)->execute(['path' => '/api/v1'])->structuredContent['totalCount']);
    }

    #[Test]
    public function executeCombinesMethodAndPathFilters(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/api/v1/users', 'Ctrl@index'),
            new RouteEntry(['POST'], '/api/v1/users', 'Ctrl@store'),
            new RouteEntry(['GET'], '/admin/dashboard', 'Ctrl@index'),
        ];
        self::assertSame(1, $this->createTool($routes)->execute(['method' => 'GET', 'path' => '/api'])->structuredContent['totalCount']);
    }

    #[Test]
    public function executePaginatesResults(): void
    {
        $routes = [];
        for ($i = 0; $i < 5; $i++) {
            $routes[] = new RouteEntry(['GET'], "/route/{$i}", "Handler{$i}");
        }
        $result = $this->createTool($routes)->execute(['limit' => 2]);

        self::assertSame(5, $result->structuredContent['totalCount']);
        /** @var list<mixed> $routes */
        $routes = $result->structuredContent['routes'];
        self::assertCount(2, $routes);
        self::assertSame('2', $result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executeReturnsEmptyWhenNoRoutes(): void
    {
        self::assertSame(0, $this->createTool([])->execute([])->structuredContent['totalCount']);
    }

    /** @param list<RouteEntry> $routes */
    private function createTool(array $routes): ReadRoutesTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData($routes),
        );

        return new ReadRoutesTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
