<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\ContributedMetadata;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Introspection\ProjectMetadataSnapshot;

use function assert;
use function is_array;

#[CoversClass(ProjectMetadataSnapshot::class)]
final class ProjectMetadataSnapshotTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0-rc.11',
            generatedAt: '2024-01-01T00:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
            contributions: [],
            warnings: ['test warning'],
        );

        self::assertSame('1.0.0-rc.11', $snapshot->frameworkVersion);
        self::assertSame('2024-01-01T00:00:00+00:00', $snapshot->generatedAt);
        self::assertSame([], $snapshot->apiSnapshot->classes);
        self::assertSame([], $snapshot->architectureMap->extensions);
        self::assertSame([], $snapshot->configSchema->schemas);
        self::assertSame([], $snapshot->commandReference->commands);
        self::assertSame([], $snapshot->routeMap->routes);
        self::assertSame([], $snapshot->contributions);
        self::assertSame(['test warning'], $snapshot->warnings);
    }

    #[Test]
    public function toArraySerializesAllSections(): void
    {
        $contribution = new ContributedMetadata(
            contributorId: 'test',
            sections: ['info' => ['key' => 'value']],
            sizeBytes: 32,
        );

        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2024-06-01T12:00:00+00:00',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
            contributions: ['test' => $contribution],
            warnings: [],
        );

        $array = $snapshot->toArray();

        self::assertSame('1.0.0', $array['framework_version']);
        self::assertSame('2024-06-01T12:00:00+00:00', $array['generated_at']);
        self::assertArrayHasKey('api_snapshot', $array);
        self::assertArrayHasKey('architecture_map', $array);
        self::assertArrayHasKey('config_schema', $array);
        self::assertArrayHasKey('command_reference', $array);
        self::assertArrayHasKey('route_map', $array);
        self::assertArrayHasKey('contributions', $array);
        self::assertArrayHasKey('warnings', $array);
        $contributions = $array['contributions'];
        assert(is_array($contributions));
        self::assertArrayHasKey('test', $contributions);
        $testContribution = $contributions['test'];
        assert(is_array($testContribution));
        self::assertSame('test', $testContribution['contributor_id']);
    }

    #[Test]
    public function defaultsToEmptyContributionsAndWarnings(): void
    {
        $snapshot = new ProjectMetadataSnapshot(
            frameworkVersion: '1.0.0',
            generatedAt: '2024-01-01',
            apiSnapshot: new ApiSnapshotData(),
            architectureMap: new ArchitectureMapData(),
            configSchema: new ConfigSchemaData(),
            commandReference: new CommandReferenceData(),
            routeMap: new RouteMapData(),
        );

        self::assertSame([], $snapshot->contributions);
        self::assertSame([], $snapshot->warnings);
    }
}
