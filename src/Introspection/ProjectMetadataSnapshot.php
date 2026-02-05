<?php

declare(strict_types=1);

namespace Pulsar\Introspection;

use Pulsar\Api\Api;
use Pulsar\Introspection\Data\ApiSnapshotData;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\ContributedMetadata;
use Pulsar\Introspection\Data\RouteMapData;

use function array_map;

/**
 * Immutable aggregate of all introspection data for the current application.
 *
 * Combines framework-level metadata (routes, commands, config schemas,
 * architecture map, API snapshot) with contributor-provided sections.
 */
#[Api(since: '1.0.0')]
final readonly class ProjectMetadataSnapshot
{
    /**
     * @param array<string, ContributedMetadata> $contributions Keyed by contributor ID
     * @param list<string>                       $warnings
     */
    public function __construct(
        public string $frameworkVersion,
        public string $generatedAt,
        public ApiSnapshotData $apiSnapshot,
        public ArchitectureMapData $architectureMap,
        public ConfigSchemaData $configSchema,
        public CommandReferenceData $commandReference,
        public RouteMapData $routeMap,
        public array $contributions = [],
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'framework_version' => $this->frameworkVersion,
            'generated_at' => $this->generatedAt,
            'api_snapshot' => $this->apiSnapshot->toArray(),
            'architecture_map' => $this->architectureMap->toArray(),
            'config_schema' => $this->configSchema->toArray(),
            'command_reference' => $this->commandReference->toArray(),
            'route_map' => $this->routeMap->toArray(),
            'contributions' => array_map(
                static fn(ContributedMetadata $c): array => $c->toArray(),
                $this->contributions,
            ),
            'warnings' => $this->warnings,
        ];
    }
}
