<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadCommandsTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandEntry;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadCommandsTool::class)]
final class ReadCommandsToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarCommandsList(): void
    {
        self::assertSame('pulsar.commands.list', $this->createTool([])->name());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool([])->category());
    }

    #[Test]
    public function executeReturnsAllCommandsWhenNoFilter(): void
    {
        $commands = [
            new CommandEntry('make:model', 'Create a model'),
            new CommandEntry('make:controller', 'Create a controller'),
            new CommandEntry('cache:clear', 'Clear cache'),
        ];
        $result = $this->createTool($commands)->execute([]);

        self::assertFalse($result->isError);
        self::assertSame(3, $result->structuredContent['totalCount']);
        /** @var list<mixed> $commands */
        $commands = $result->structuredContent['commands'];
        self::assertCount(3, $commands);
    }

    #[Test]
    public function executeFiltersByNamespace(): void
    {
        $commands = [
            new CommandEntry('make:model', 'Create a model'),
            new CommandEntry('make:controller', 'Create a controller'),
            new CommandEntry('cache:clear', 'Clear cache'),
        ];
        $result = $this->createTool($commands)->execute(['namespace' => 'make']);

        self::assertSame(2, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function executePaginatesResults(): void
    {
        $commands = [];
        for ($i = 0; $i < 5; $i++) {
            $commands[] = new CommandEntry("cmd:{$i}", "Command {$i}");
        }
        $result = $this->createTool($commands)->execute(['limit' => 2]);

        self::assertSame(5, $result->structuredContent['totalCount']);
        /** @var list<mixed> $cmds */
        $cmds = $result->structuredContent['commands'];
        self::assertCount(2, $cmds);
        self::assertSame('2', $result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executePaginatesWithCursor(): void
    {
        $commands = [];
        for ($i = 0; $i < 5; $i++) {
            $commands[] = new CommandEntry("cmd:{$i}", "Command {$i}");
        }
        $result = $this->createTool($commands)->execute(['cursor' => '3', 'limit' => 10]);

        /** @var list<mixed> $cmds */
        $cmds = $result->structuredContent['commands'];
        self::assertCount(2, $cmds);
        self::assertNull($result->structuredContent['nextCursor']);
    }

    #[Test]
    public function executeReturnsEmptyWhenNoCommands(): void
    {
        $result = $this->createTool([])->execute([]);

        self::assertSame(0, $result->structuredContent['totalCount']);
        self::assertSame([], $result->structuredContent['commands']);
    }

    /** @param list<CommandEntry> $commands */
    private function createTool(array $commands): ReadCommandsTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData($commands),
            routeMap: new RouteMapData(),
        );

        return new ReadCommandsTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
