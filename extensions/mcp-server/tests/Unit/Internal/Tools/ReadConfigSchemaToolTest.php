<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadConfigSchemaTool;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\ConfigSchemaEntry;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

#[CoversClass(ReadConfigSchemaTool::class)]
final class ReadConfigSchemaToolTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarConfigSchema(): void
    {
        self::assertSame('pulsar.config.schema', $this->createTool([])->name());
    }

    #[Test]
    public function categoryIsRead(): void
    {
        self::assertSame(ToolCategory::Read, $this->createTool([])->category());
    }

    #[Test]
    public function executeReturnsAllSchemasWhenNoFilter(): void
    {
        $schemas = [
            new ConfigSchemaEntry('App\\Config\\AppConfig'),
            new ConfigSchemaEntry('App\\Config\\DbConfig'),
        ];
        $result = $this->createTool($schemas)->execute([]);

        self::assertFalse($result->isError);
        /** @var list<mixed> $schemas */
        $schemas = $result->structuredContent['schemas'];
        self::assertCount(2, $schemas);
    }

    #[Test]
    public function executeFiltersByClassName(): void
    {
        $schemas = [
            new ConfigSchemaEntry('App\\Config\\AppConfig'),
            new ConfigSchemaEntry('App\\Config\\DbConfig'),
        ];
        $result = $this->createTool($schemas)->execute(['name' => 'DbConfig']);

        /** @var list<mixed> $filteredSchemas */
        $filteredSchemas = $result->structuredContent['schemas'];
        self::assertCount(1, $filteredSchemas);
    }

    #[Test]
    public function executeReturnsEmptyForNonMatchingFilter(): void
    {
        $schemas = [new ConfigSchemaEntry('App\\Config\\AppConfig')];
        $result = $this->createTool($schemas)->execute(['name' => 'NonExistent']);

        self::assertSame([], $result->structuredContent['schemas']);
    }

    #[Test]
    public function executeReturnsEmptyWhenNoSchemas(): void
    {
        $result = $this->createTool([])->execute([]);

        self::assertSame([], $result->structuredContent['schemas']);
    }

    /** @param list<ConfigSchemaEntry> $schemas */
    private function createTool(array $schemas): ReadConfigSchemaTool
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2026-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData($schemas),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
        );

        return new ReadConfigSchemaTool(MetadataServiceFactory::withSnapshot($snapshot));
    }
}
