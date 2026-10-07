<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;

/**
 * Persistence layer for health check history and incidents.
 * @api
 */
#[Api(since: '1.0.0')]
interface HealthHistoryStoreInterface
{
    /**
     * Store a health snapshot.
     */
    public function storeSnapshot(HealthSnapshot $snapshot): void;

    /**
     * Retrieve the most recent snapshots, ordered by captured_at DESC.
     *
     * @return list<HealthSnapshot>
     */
    public function recentSnapshots(int $limit = 50): array;

    /**
     * Retrieve snapshots captured within the given time range.
     *
     * @return list<HealthSnapshot>
     */
    public function snapshotsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Store a new incident.
     */
    public function storeIncident(Incident $incident): void;

    /**
     * Update an existing incident (e.g., acknowledge, resolve).
     */
    public function updateIncident(Incident $incident): void;

    /**
     * Retrieve active incidents (status = open or acknowledged).
     *
     * @return list<Incident>
     */
    public function activeIncidents(): array;

    /**
     * Retrieve recent incidents, ordered by started_at DESC.
     *
     * @return list<Incident>
     */
    public function recentIncidents(int $limit = 20): array;

    /**
     * Delete snapshots older than the given cutoff.
     *
     * @return int Number of deleted rows
     */
    public function deleteSnapshotsOlderThan(DateTimeImmutable $cutoff): int;

    /**
     * Count total stored snapshots.
     */
    public function snapshotCount(): int;
}
