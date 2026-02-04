<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Pulsar\Api\Api;

/**
 * Reports and queries security incidents.
 *
 * Implementations are responsible for persisting incident records and
 * optionally triggering notifications or escalation workflows based on
 * severity thresholds.
 */
#[Api(since: '1.0.0')]
interface IncidentReporterInterface
{
    /**
     * Report a new security incident.
     *
     * The implementation should persist the incident and trigger any
     * configured notification or escalation workflows.
     *
     * @param IncidentSeverity       $severity    Incident severity level
     * @param string                 $title       Short incident title
     * @param string                 $description Detailed description
     * @param string                 $source      Actor or source identifier
     * @param array<string, mixed>   $metadata    Additional contextual data
     */
    public function report(
        IncidentSeverity $severity,
        string $title,
        string $description,
        string $source = '',
        array $metadata = [],
    ): IncidentInterface;

    /**
     * Retrieve an incident by its unique identifier.
     *
     * Returns null if no incident with the given ID exists.
     */
    public function find(string $id): ?IncidentInterface;

    /**
     * Retrieve recent incidents, optionally filtered by minimum severity.
     *
     * Results are ordered by report timestamp descending (most recent first).
     *
     * @param int                   $limit          Maximum number of incidents to return
     * @param IncidentSeverity|null $minSeverity    If provided, only return incidents at
     *                                              or above this severity level
     *
     * @return list<IncidentInterface>
     */
    public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array;
}
