<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadArchitectureMapTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadArchitectureMapTool::class)]
final class ReadArchitectureMapToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarArchitectureMap(): void
    {
        self::assertSame('pulsar.architecture.map', $this->createTool(new ArchitectureMapData())->name());
    }

    #[Test]
    public function descriptionIsNonEmpty(): void
    {
        self::assertNotSame('', $this->createTool(new ArchitectureMapData())->description());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool(new ArchitectureMapData())->category());
    }

    #[Test]
    public function inputSchemaIsEmptyObject(): void
    {
        self::assertSame('object', $this->createTool(new ArchitectureMapData())->inputSchema()['type']);
    }

    #[Test]
    public function outputSchemaHasExtensionsAndBindings(): void
    {
        $schema = $this->createTool(new ArchitectureMapData())->outputSchema();
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('extensions', $properties);
        self::assertArrayHasKey('bindings', $properties);
    }

    #[Test]
    public function executeReturnsArchitectureMapData(): void
    {
        $tool = $this->createTool(new ArchitectureMapData(bindings: ['App\\Service\\FooInterface']));
        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        /** @var list<string> $bindings */
        $bindings = $result->structuredContent['bindings'];
        self::assertContains('App\\Service\\FooInterface', $bindings);
    }

    #[Test]
    public function executeReturnsEmptyMapWhenNoData(): void
    {
        $result = $this->createTool(new ArchitectureMapData())->execute([]);

        self::assertSame([], $result->structuredContent['extensions']);
        self::assertSame([], $result->structuredContent['bindings']);
    }

    private function createTool(ArchitectureMapData $archMap): ReadArchitectureMapTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: $archMap,
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
        );

        return new ReadArchitectureMapTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
