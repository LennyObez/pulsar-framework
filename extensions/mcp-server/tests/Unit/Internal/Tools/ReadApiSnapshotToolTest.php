<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadApiSnapshotTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadApiSnapshotTool::class)]
final class ReadApiSnapshotToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarApiSnapshot(): void
    {
        self::assertSame('pulsar.api.snapshot', $this->createTool([])->name());
    }

    #[Test]
    public function descriptionIsNonEmpty(): void
    {
        self::assertNotSame('', $this->createTool([])->description());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool([])->category());
    }

    #[Test]
    public function inputSchemaHasExpectedProperties(): void
    {
        $schema = $this->createTool([])->inputSchema();

        self::assertSame('object', $schema['type']);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('class', $properties);
        self::assertArrayHasKey('namespacePrefix', $properties);
    }

    #[Test]
    public function executeReturnsSuccessWithEmptySnapshot(): void
    {
        $result = $this->createTool([])->execute([]);

        self::assertFalse($result->isError);
        self::assertSame(0, $result->structuredContent['totalCount']);
        self::assertNull($result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executeFiltersByExactClassName(): void
    {
        $tool = $this->createTool([
            'App\\Foo' => ['since' => '1.0', 'methods' => [], 'constants' => []],
            'App\\Bar' => ['since' => '1.0', 'methods' => [], 'constants' => []],
        ]);

        $result = $tool->execute(['class' => 'App\\Foo']);

        self::assertSame(1, $result->structuredContent['totalCount']);
        /** @var array<string, mixed> $classes */
        $classes = $result->structuredContent['classes'];
        self::assertArrayHasKey('App\\Foo', $classes);
    }

    #[Test]
    public function executeReturnsEmptyWhenClassNotFound(): void
    {
        $tool = $this->createTool(['App\\Foo' => ['since' => '1.0', 'methods' => [], 'constants' => []]]);

        $result = $tool->execute(['class' => 'App\\NonExistent']);
        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executeFiltersByNamespacePrefix(): void
    {
        $tool = $this->createTool([
            'App\\Core\\Foo' => ['since' => '1.0', 'methods' => [], 'constants' => []],
            'App\\Core\\Bar' => ['since' => '1.0', 'methods' => [], 'constants' => []],
            'Vendor\\Lib\\Baz' => ['since' => '1.0', 'methods' => [], 'constants' => []],
        ]);

        self::assertSame(2, $tool->execute(['namespacePrefix' => 'App\\Core'])->structuredContent['totalCount']);
    }

    #[Test]
    public function executePaginatesWithLimitAndCursor(): void
    {
        $classes = [];
        for ($i = 0; $i < 5; $i++) {
            $classes["Class{$i}"] = ['since' => '1.0', 'methods' => [], 'constants' => []];
        }
        $result = $this->createTool($classes)->execute(['limit' => 2]);

        self::assertSame(5, $result->structuredContent['totalCount']);
        /** @var array<string, mixed> $resultClasses */
        $resultClasses = $result->structuredContent['classes'];
        self::assertCount(2, $resultClasses);
        self::assertSame('Class2', $result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executeCursorSkipsToPosition(): void
    {
        $classes = [];
        for ($i = 0; $i < 5; $i++) {
            $classes["Class{$i}"] = ['since' => '1.0', 'methods' => [], 'constants' => []];
        }
        $result = $this->createTool($classes)->execute(['cursor' => 'Class3', 'limit' => 10]);

        /** @var array<string, mixed> $resultClasses */
        $resultClasses = $result->structuredContent['classes'];
        self::assertCount(2, $resultClasses);
        self::assertArrayHasKey('Class3', $resultClasses);
    }

    /** @param array<string, array{since: string, methods: list<string>, constants: list<string>}> $classes */
    private function createTool(array $classes): ReadApiSnapshotTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData($classes),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
        );

        return new ReadApiSnapshotTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
