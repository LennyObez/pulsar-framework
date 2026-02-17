<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Override;
use Pulsar\Api\Api;

use function array_filter;
use function array_slice;
use function array_values;
use function usort;

/**
 * In-memory incident reporter for testing and development.
 *
 * Stores incidents in a PHP array. All data is lost when the process ends.
 * Production deployments should use FileIncidentReporter or a database-backed
 * implementation.
 */
#[Api(since: '1.0.0')]
final class InMemoryIncidentReporter implements IncidentReporterInterface
{
    /** @var array<string, Incident> */
    private array $incidents = [];

    #[Override]
    public function report(
        IncidentSeverity $severity,
        string $title,
        string $description,
        string $source = '',
        array $metadata = [],
    ): IncidentInterface {
        $incident = Incident::create($severity, $title, $description, $source, $metadata);
        $this->incidents[$incident->id()] = $incident;

        return $incident;
    }

    #[Override]
    public function find(string $id): ?IncidentInterface
    {
        return $this->incidents[$id] ?? null;
    }

    #[Override]
    public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
    {
        $filtered = $this->incidents;

        if ($minSeverity !== null) {
            $minOrdinal = self::severityOrdinal($minSeverity);
            $filtered = array_filter(
                $filtered,
                static fn(Incident $i): bool => self::severityOrdinal($i->severity()) >= $minOrdinal,
            );
        }

        $sorted = array_values($filtered);
        usort(
            $sorted,
            static fn(Incident $a, Incident $b): int => $b->reportedAt()->getTimestamp() <=> $a->reportedAt()->getTimestamp(),
        );

        return array_slice($sorted, 0, $limit);
    }

    /**
     * Map severity to a numeric ordinal for comparison.
     */
    private static function severityOrdinal(IncidentSeverity $severity): int
    {
        return match ($severity) {
            IncidentSeverity::Low => 0,
            IncidentSeverity::Medium => 1,
            IncidentSeverity::High => 2,
            IncidentSeverity::Critical => 3,
        };
    }
}
