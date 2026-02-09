<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents a security incident record.
 *
 * Incidents capture security-relevant events that require investigation,
 * such as failed authentication spikes, unauthorized access attempts,
 * data breaches, or integrity violations. Implementations should be
 * immutable value objects.
 */
#[Api(since: '1.0.0')]
interface IncidentInterface
{
    /**
     * Get the unique identifier for this incident.
     */
    public function id(): string;

    /**
     * Get the severity classification.
     */
    public function severity(): IncidentSeverity;

    /**
     * Get a short human-readable title describing the incident.
     */
    public function title(): string;

    /**
     * Get a detailed description of what occurred.
     */
    public function description(): string;

    /**
     * Get the timestamp when the incident was detected or reported.
     */
    public function reportedAt(): DateTimeImmutable;

    /**
     * Get the actor or source that triggered the incident.
     *
     * This may be a user ID, IP address, service name, or "system" for
     * automated detections. Returns an empty string if unknown.
     */
    public function source(): string;

    /**
     * Get structured metadata associated with the incident.
     *
     * May include request IDs, affected resource identifiers, stack traces,
     * or other contextual data useful for investigation.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
