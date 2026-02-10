<?php

declare(strict_types=1);

namespace Pulsar\Introspection;

use function array_slice;
use function count;
use function mb_substr;

use Pulsar\Api\Api;
use Pulsar\Core\Version;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

/**
 * Orchestrates project metadata collection from all sources.
 *
 * Assembles a ProjectMetadataSnapshot from the runtime probe, config
 * schema reflector, API snapshot file, and registered contributors.
 * The snapshot is memoized per-process to avoid redundant work.
 */
#[Api(since: '1.0.0')]
final class ProjectMetadataService
{
    private const int MAX_TOTAL_BYTES = 4_194_304; // 4 MB
    private const int MAX_WARNINGS = 100;
    private const int MAX_WARNING_LENGTH = 512;

    private ?ProjectMetadataSnapshot $cached = null;

    /**
     * @param list<MetadataContributorInterface> $contributors
     * @param list<class-string>                 $configClasses Config DTO classes to reflect
     */
    public function __construct(
        private readonly CoreRuntimeProbe $probe,
        private readonly ConfigSchemaReflector $schemaReflector,
        private readonly SnapshotFileReader $snapshotReader,
        private readonly SensitiveDataScrubber $scrubber,
        private readonly array $contributors,
        private readonly array $configClasses,
    ) {}

    /**
     * Get the project metadata snapshot.
     *
     * Memoized per-process — returns the cached snapshot after the first call.
     */
    public function snapshot(): ProjectMetadataSnapshot
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $warnings = [];

        // Gather sections from internal probes
        $apiSnapshot = $this->snapshotReader->read($warnings);
        $architectureMap = $this->probe->probeArchitecture($warnings);
        $configSchema = $this->schemaReflector->reflect($this->configClasses, $warnings);
        $commandReference = $this->probe->probeCommands($warnings);
        $routeMap = $this->probe->probeRoutes($warnings);

        // Gather contributor metadata with per-contributor + global limits
        $contributions = [];
        $totalBytes = 0;

        foreach ($this->contributors as $contributor) {
            $builder = ProjectMetadataBuilder::forContributor($contributor->id());
            $contributor->contribute($builder);
            $contribution = $builder->build($this->scrubber);

            $totalBytes += $contribution->sizeBytes();

            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $warnings[] = "Global contribution limit exceeded at contributor '{$contributor->id()}'.";

                break;
            }

            $contributions[$contributor->id()] = $contribution;

            foreach ($builder->warnings() as $warning) {
                $warnings[] = $warning;
            }
        }

        // Cap warnings
        $warnings = array_map(
            static fn(string $w): string => mb_substr($w, 0, self::MAX_WARNING_LENGTH),
            $warnings,
        );

        if (count($warnings) > self::MAX_WARNINGS) {
            $warnings = array_slice($warnings, 0, self::MAX_WARNINGS);
        }

        $this->cached = new ProjectMetadataSnapshot(
            frameworkVersion: Version::full(),
            generatedAt: date('c'),
            apiSnapshot: $apiSnapshot,
            architectureMap: $architectureMap,
            configSchema: $configSchema,
            commandReference: $commandReference,
            routeMap: $routeMap,
            contributions: $contributions,
            warnings: $warnings,
        );

        return $this->cached;
    }
}
