<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadContainerBindingsTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadContainerBindingsTool::class)]
final class ReadContainerBindingsToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarContainerBindings(): void
    {
        self::assertSame('pulsar.container.bindings', $this->createTool([])->name());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool([])->category());
    }

    #[Test]
    public function executeReturnsAllBindingsWhenNoFilter(): void
    {
        $result = $this->createTool(['App\\Foo', 'App\\Bar', 'Vendor\\Baz'])->execute([]);

        self::assertFalse($result->isError);
        self::assertSame(3, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executeFiltersBySubstring(): void
    {
        $result = $this->createTool(['App\\Service\\Foo', 'App\\Service\\Bar', 'Vendor\\Baz'])
            ->execute(['filter' => 'Service']);

        self::assertSame(2, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executePaginatesResults(): void
    {
        $bindings = [];
        for ($i = 0; $i < 10; $i++) {
            $bindings[] = "App\\Binding{$i}";
        }
        $result = $this->createTool($bindings)->execute(['limit' => 3]);

        self::assertSame(10, $result->structuredContent['totalCount']);
        /** @var list<string> $bindings */
        $bindings = $result->structuredContent['bindings'];
        self::assertCount(3, $bindings);
        self::assertSame('3', $result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executePaginatesWithCursor(): void
    {
        $bindings = [];
        for ($i = 0; $i < 5; $i++) {
            $bindings[] = "App\\Binding{$i}";
        }
        $result = $this->createTool($bindings)->execute(['cursor' => '4', 'limit' => 10]);

        /** @var list<string> $resultBindings */
        $resultBindings = $result->structuredContent['bindings'];
        self::assertCount(1, $resultBindings);
        self::assertNull($result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executeReturnsEmptyWhenNoBindings(): void
    {
        self::assertSame(0, $this->createTool([])->execute([])->structuredContent['totalCount']);
    }

    /** @param list<string> $bindings */
    private function createTool(array $bindings): ReadContainerBindingsTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(bindings: $bindings),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
        );

        return new ReadContainerBindingsTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
