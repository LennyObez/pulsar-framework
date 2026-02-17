<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Internal\Detection;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Contracts\IncidentDetectorInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;

use function array_key_exists;
use function bin2hex;
use function random_bytes;

/**
 * Detects incidents based on consecutive failure thresholds.
 *
 * When a check fails for N consecutive snapshots (threshold), a new
 * incident is created. When a check recovers (returns healthy), any
 * active incident for that check is auto-resolved.
 *
 * Severity mapping:
 * - Degraded check -> Minor
 * - Unhealthy check -> Major
 * - Multiple unhealthy checks -> Critical (escalation)
 */
#[Internal(reason: 'Use IncidentDetectorInterface for type declarations')]
final readonly class ThresholdIncidentDetector implements IncidentDetectorInterface
{
    public function __construct(
        private int $threshold = 3,
    ) {}

    #[Override]
    public function detect(HealthSnapshot $snapshot, HealthHistoryStoreInterface $store): array
    {
        $activeIncidents = $store->activeIncidents();
        $activeByCheck = $this->indexIncidentsByCheck($activeIncidents);

        $incidents = [];

        // Check for recoveries: active incidents whose checks are now healthy
        foreach ($activeIncidents as $incident) {
            if ($this->checkIsHealthy($incident->checkName, $snapshot)) {
                $incidents[] = $incident->resolve(new DateTimeImmutable());
            }
        }

        // Check for new incidents: checks that are failing
        $recentSnapshots = $store->recentSnapshots($this->threshold);
        $failingChecks = $this->findConsecutivelyFailingChecks($snapshot, $recentSnapshots);

        foreach ($failingChecks as $checkName => $status) {
            // Skip if already tracked by an active incident
            if (array_key_exists($checkName, $activeByCheck)) {
                continue;
            }

            $severity = $this->determineSeverity($status, $snapshot);
            $message = $this->extractMessage($checkName, $snapshot);

            $incidents[] = new Incident(
                id: bin2hex(random_bytes(16)),
                checkName: $checkName,
                severity: $severity,
                status: IncidentStatus::Open,
                message: $message,
                startedAt: new DateTimeImmutable(),
            );
        }

        return $incidents;
    }

    /**
     * @param list<Incident> $incidents
     * @return array<string, Incident>
     */
    private function indexIncidentsByCheck(array $incidents): array
    {
        $index = [];

        foreach ($incidents as $incident) {
            $index[$incident->checkName] = $incident;
        }

        return $index;
    }

    private function checkIsHealthy(string $checkName, HealthSnapshot $snapshot): bool
    {
        foreach ($snapshot->results as $result) {
            if ($result['name'] === $checkName && $result['status'] === 'healthy') {
                return true;
            }
        }

        return false;
    }

    /**
     * Find checks that have been failing for threshold consecutive snapshots.
     *
     * @param list<HealthSnapshot> $recentSnapshots Prior snapshots (most recent first)
     * @return array<string, string> Map of check name to current status
     */
    private function findConsecutivelyFailingChecks(
        HealthSnapshot $currentSnapshot,
        array $recentSnapshots,
    ): array {
        $failingChecks = [];

        foreach ($currentSnapshot->results as $result) {
            $checkName = $result['name'];
            $status = $result['status'];

            if ($status === 'healthy') {
                continue;
            }

            // Count consecutive failures in prior snapshots
            $consecutiveFailures = 1; // Current snapshot counts as 1

            foreach ($recentSnapshots as $prior) {
                $priorStatus = $this->getCheckStatus($checkName, $prior);

                if ($priorStatus === null || $priorStatus === 'healthy') {
                    break;
                }

                $consecutiveFailures++;
            }

            if ($consecutiveFailures >= $this->threshold) {
                $failingChecks[$checkName] = $status;
            }
        }

        return $failingChecks;
    }

    private function getCheckStatus(string $checkName, HealthSnapshot $snapshot): ?string
    {
        foreach ($snapshot->results as $result) {
            if ($result['name'] === $checkName) {
                return $result['status'];
            }
        }

        return null;
    }

    private function determineSeverity(string $status, HealthSnapshot $snapshot): IncidentSeverity
    {
        if ($status === 'degraded') {
            return IncidentSeverity::Minor;
        }

        // Count total unhealthy checks in this snapshot
        $unhealthyCount = 0;

        foreach ($snapshot->results as $result) {
            if ($result['status'] === 'unhealthy') {
                $unhealthyCount++;
            }
        }

        if ($unhealthyCount > 1) {
            return IncidentSeverity::Critical;
        }

        return IncidentSeverity::Major;
    }

    private function extractMessage(string $checkName, HealthSnapshot $snapshot): string
    {
        foreach ($snapshot->results as $result) {
            if ($result['name'] === $checkName) {
                return $result['message'];
            }
        }

        return 'Health check failed';
    }
}
