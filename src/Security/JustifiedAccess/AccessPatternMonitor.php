<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;
use Pulsar\Testing\Clock\ClockInterface;

/**
 * Monitors access patterns for anomalies.
 *
 * Detects suspicious data access behaviors such as:
 * - Accessing an unusually high number of unique resources in a time window
 * - Break-the-glass activations
 *
 * Integrates with the audit logger and incident reporter to create
 * a complete compliance trail for PCI-DSS, HIPAA, and SOC 2.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AccessPatternMonitor
{
    public function __construct(
        private JustifiedAccessConfig $config,
        private JustificationStoreInterface $store,
        private AuditLoggerInterface $auditLogger,
        private IncidentReporterInterface $incidentReporter,
        private ClockInterface $clock,
    ) {}

    /**
     * Check if an actor's access patterns are anomalous.
     *
     * Returns true if the actor has exceeded the anomaly threshold.
     */
    public function isAnomalous(string $actorId): bool
    {
        $since = $this->clock->now()->modify('-' . $this->config->anomalyWindowSeconds . ' seconds');
        $uniqueCount = $this->store->countUniqueResourcesByActor($actorId, $since);

        return $uniqueCount >= $this->config->anomalyThreshold;
    }

    /**
     * Evaluate an actor's access pattern and report anomalies.
     *
     * Should be called after recording a new justified access. If the
     * actor's access pattern exceeds the configured threshold, an
     * incident is reported and an audit trail entry is created.
     *
     * @return bool True if an anomaly was detected
     */
    public function evaluate(string $actorId): bool
    {
        if (!$this->isAnomalous($actorId)) {
            return false;
        }

        $since = $this->clock->now()->modify('-' . $this->config->anomalyWindowSeconds . ' seconds');
        $uniqueCount = $this->store->countUniqueResourcesByActor($actorId, $since);

        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: $actorId,
            action: 'access_pattern.anomaly_detected',
            resource: 'actor:' . $actorId,
            metadata: [
                'unique_resources_accessed' => $uniqueCount,
                'threshold' => $this->config->anomalyThreshold,
                'window_seconds' => $this->config->anomalyWindowSeconds,
            ],
        );

        $this->incidentReporter->report(
            severity: IncidentSeverity::Medium,
            title: 'Unusual access pattern detected',
            description: 'Actor ' . $actorId . ' accessed ' . $uniqueCount
                . ' unique resources in ' . $this->config->anomalyWindowSeconds
                . ' seconds (threshold: ' . $this->config->anomalyThreshold . ')',
            source: $actorId,
            metadata: [
                'unique_resources_accessed' => $uniqueCount,
                'threshold' => $this->config->anomalyThreshold,
                'window_seconds' => $this->config->anomalyWindowSeconds,
            ],
        );

        return true;
    }

    /**
     * Get the current number of unique resources accessed by an actor
     * within the anomaly detection window.
     */
    public function currentAccessCount(string $actorId): int
    {
        $since = $this->clock->now()->modify('-' . $this->config->anomalyWindowSeconds . ' seconds');

        return $this->store->countUniqueResourcesByActor($actorId, $since);
    }

    /**
     * Check if an actor has any active break-the-glass sessions.
     *
     * @return list<JustificationRecord>
     */
    public function activeBreakTheGlassSessions(string $actorId): array
    {
        $records = $this->store->findActiveBreakTheGlass($actorId);
        $now = $this->clock->now();

        $active = [];
        foreach ($records as $record) {
            $expires = $record->accessTimestamp->modify('+' . $this->config->breakTheGlassDuration . ' seconds');

            if ($expires > $now) {
                $active[] = $record;
            }
        }

        return $active;
    }
}
