<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Internal\Tools\ReadApiSnapshotTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadArchitectureMapTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadCommandsTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadConfigSchemaTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadContainerBindingsTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadRoutesTool;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(ReadApiSnapshotTool::class)]
#[CoversClass(ReadArchitectureMapTool::class)]
#[CoversClass(ReadCommandsTool::class)]
#[CoversClass(ReadConfigSchemaTool::class)]
#[CoversClass(ReadContainerBindingsTool::class)]
#[CoversClass(ReadRoutesTool::class)]
final class ReadToolsTest extends TestCase
{
    private ProjectMetadataService $metadataService;

    protected function setUp(): void
    {
        $scrubber = new SensitiveDataScrubber();

        // Build a container that returns known bindings
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('getBindings')->willReturn([]);

        $probe = new CoreRuntimeProbe($container, null, null, null);
        $reflector = new ConfigSchemaReflector($scrubber);

        // SnapshotFileReader needs to find the api snapshot file
        $reader = new SnapshotFileReader(__DIR__);

        $this->metadataService = new ProjectMetadataService(
            probe: $probe,
            schemaReflector: $reflector,
            snapshotReader: $reader,
            scrubber: $scrubber,
            contributors: [],
            configClasses: [],
        );
    }

    // --- ReadApiSnapshotTool ---

    #[Test]
    public function apiSnapshotToolMetadata(): void
    {
        $tool = new ReadApiSnapshotTool($this->metadataService);

        self::assertSame('pulsar.api.snapshot', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
        self::assertArrayHasKey('type', $tool->inputSchema());
        self::assertArrayHasKey('type', $tool->outputSchema());
    }

    #[Test]
    public function apiSnapshotExecutesWithoutError(): void
    {
        $tool = new ReadApiSnapshotTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('totalCount', $result->structuredContent);
        self::assertArrayHasKey('classes', $result->structuredContent);
        self::assertArrayHasKey('nextCursor', $result->structuredContent);
    }

    #[Test]
    public function apiSnapshotWithLimitPaginates(): void
    {
        $tool = new ReadApiSnapshotTool($this->metadataService);

        $result = $tool->execute(['limit' => 1]);

        self::assertFalse($result->isError);
        self::assertIsInt($result->structuredContent['totalCount']);
    }

    #[Test]
    public function apiSnapshotFilterByNonexistentClass(): void
    {
        $tool = new ReadApiSnapshotTool($this->metadataService);

        $result = $tool->execute(['class' => 'NonExistent\\Class']);

        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function apiSnapshotFilterByNamespace(): void
    {
        $tool = new ReadApiSnapshotTool($this->metadataService);

        $result = $tool->execute(['namespacePrefix' => 'Pulsar\\ZzDoesNotExist']);

        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    // --- ReadArchitectureMapTool ---

    #[Test]
    public function architectureMapToolMetadata(): void
    {
        $tool = new ReadArchitectureMapTool($this->metadataService);

        self::assertSame('pulsar.architecture.map', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
    }

    #[Test]
    public function architectureMapReturnsData(): void
    {
        $tool = new ReadArchitectureMapTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('extensions', $result->structuredContent);
        self::assertArrayHasKey('bindings', $result->structuredContent);
    }

    // --- ReadCommandsTool ---

    #[Test]
    public function commandsToolMetadata(): void
    {
        $tool = new ReadCommandsTool($this->metadataService);

        self::assertSame('pulsar.commands.list', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
    }

    #[Test]
    public function commandsReturnsData(): void
    {
        $tool = new ReadCommandsTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('totalCount', $result->structuredContent);
        self::assertArrayHasKey('commands', $result->structuredContent);
    }

    #[Test]
    public function commandsFilterByNonexistentNamespace(): void
    {
        $tool = new ReadCommandsTool($this->metadataService);

        $result = $tool->execute(['namespace' => 'zznonexistent']);

        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function commandsPagination(): void
    {
        $tool = new ReadCommandsTool($this->metadataService);

        $result = $tool->execute(['limit' => 1]);

        self::assertFalse($result->isError);
        self::assertIsInt($result->structuredContent['totalCount']);
    }

    // --- ReadConfigSchemaTool ---

    #[Test]
    public function configSchemaToolMetadata(): void
    {
        $tool = new ReadConfigSchemaTool($this->metadataService);

        self::assertSame('pulsar.config.schema', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
    }

    #[Test]
    public function configSchemaReturnsData(): void
    {
        $tool = new ReadConfigSchemaTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('schemas', $result->structuredContent);
    }

    #[Test]
    public function configSchemaFilterByNonexistentName(): void
    {
        $tool = new ReadConfigSchemaTool($this->metadataService);

        $result = $tool->execute(['name' => 'ZzNonExistentConfig']);

        self::assertEmpty($result->structuredContent['schemas']);
    }

    // --- ReadContainerBindingsTool ---

    #[Test]
    public function containerBindingsToolMetadata(): void
    {
        $tool = new ReadContainerBindingsTool($this->metadataService);

        self::assertSame('pulsar.container.bindings', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
    }

    #[Test]
    public function containerBindingsReturnsData(): void
    {
        $tool = new ReadContainerBindingsTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('totalCount', $result->structuredContent);
        self::assertArrayHasKey('bindings', $result->structuredContent);
    }

    #[Test]
    public function containerBindingsFilterBySubstring(): void
    {
        $tool = new ReadContainerBindingsTool($this->metadataService);

        $result = $tool->execute(['filter' => 'ZzNonExistentBinding']);

        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function containerBindingsPagination(): void
    {
        $tool = new ReadContainerBindingsTool($this->metadataService);

        $result = $tool->execute(['limit' => 1]);

        self::assertFalse($result->isError);
        self::assertIsInt($result->structuredContent['totalCount']);
    }

    // --- ReadRoutesTool ---

    #[Test]
    public function routesToolMetadata(): void
    {
        $tool = new ReadRoutesTool($this->metadataService);

        self::assertSame('pulsar.routes.list', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Read, $tool->category());
    }

    #[Test]
    public function routesReturnsData(): void
    {
        $tool = new ReadRoutesTool($this->metadataService);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertArrayHasKey('totalCount', $result->structuredContent);
        self::assertArrayHasKey('routes', $result->structuredContent);
    }

    #[Test]
    public function routesFilterByMethod(): void
    {
        $tool = new ReadRoutesTool($this->metadataService);

        $result = $tool->execute(['method' => 'PATCH']);

        // No routes with PATCH method
        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function routesFilterByPath(): void
    {
        $tool = new ReadRoutesTool($this->metadataService);

        $result = $tool->execute(['path' => 'zznonexistent']);

        self::assertSame(0, $result->structuredContent['totalCount']);
    }

    #[Test]
    public function routesPagination(): void
    {
        $tool = new ReadRoutesTool($this->metadataService);

        $result = $tool->execute(['limit' => 1]);

        self::assertFalse($result->isError);
        self::assertIsInt($result->structuredContent['totalCount']);
    }
}
