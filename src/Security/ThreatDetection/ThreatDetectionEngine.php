<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

/**
 * Orchestrates threat detection across all registered detectors.
 *
 * Receives security events, correlates across detectors, and takes
 * configured response actions. Feeds detections into the incident
 * reporter and audit logger for compliance-mandated recording.
 *
 * Compliance: DORA Art.17 (incident detection), NIS2 Art.21(b),
 * PCI-DSS Req.11, ISO 27001 A.8.16.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreatDetectionEngine
{
    /** @var list<ThreatDetectorInterface> */
    private array $detectors;

    /**
     * @param list<ThreatDetectorInterface> $detectors
     */
    public function __construct(
        array $detectors,
        private IncidentReporterInterface $incidentReporter,
        private AuditLoggerInterface $auditLogger,
        private ThreatDetectionConfig $config,
    ) {
        $this->detectors = $detectors;
    }

    /**
     * Analyze a request across all detectors.
     *
     * Returns the highest-severity ThreatEvent if any detector fires,
     * or null if no threat is detected. When a threat is detected,
     * it is automatically reported as an incident and audit logged.
     *
     * @return list<ThreatEvent>
     */
    public function analyze(ServerRequestInterface $request): array
    {
        if (!$this->config->enabled) {
            return [];
        }

        $threats = [];

        foreach ($this->detectors as $detector) {
            $event = $detector->analyze($request);

            if ($event !== null) {
                $threats[] = $event;
                $this->reportThreat($event);
            }
        }

        return $threats;
    }

    /**
     * Feed a security event into all detectors for correlation.
     *
     * @param array<string, mixed> $context
     */
    public function recordEvent(string $eventType, array $context): void
    {
        if (!$this->config->enabled) {
            return;
        }

        foreach ($this->detectors as $detector) {
            $detector->recordEvent($eventType, $context);
        }
    }

    /**
     * Determine the most severe response action from a list of threats.
     *
     * @param list<ThreatEvent> $threats
     */
    public static function highestSeverityAction(array $threats): ?ThreatResponse
    {
        if ($threats === []) {
            return null;
        }

        $priority = [
            ThreatResponse::Block->value => 5,
            ThreatResponse::Challenge->value => 4,
            ThreatResponse::RateLimit->value => 3,
            ThreatResponse::Alert->value => 2,
            ThreatResponse::Log->value => 1,
        ];

        $highest = null;
        $highestPriority = 0;

        foreach ($threats as $threat) {
            $p = $priority[$threat->recommendedAction->value] ?? 0;

            if ($p > $highestPriority) {
                $highestPriority = $p;
                $highest = $threat->recommendedAction;
            }
        }

        return $highest;
    }

    private function reportThreat(ThreatEvent $event): void
    {
        $severity = match ($event->recommendedAction) {
            ThreatResponse::Block => IncidentSeverity::High,
            ThreatResponse::Challenge => IncidentSeverity::Medium,
            ThreatResponse::RateLimit => IncidentSeverity::Medium,
            ThreatResponse::Alert => IncidentSeverity::Low,
            ThreatResponse::Log => IncidentSeverity::Low,
        };

        $this->incidentReporter->report(
            severity: $severity,
            title: "Threat detected: {$event->category->value}",
            description: $event->description,
            source: $event->sourceIp,
            metadata: $event->metadata,
        );

        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Denied,
            actor: $event->sourceIp,
            action: 'threat_detection.' . $event->category->value,
            resource: '',
            metadata: [
                'category' => $event->category->value,
                'recommended_action' => $event->recommendedAction->value,
                'confidence' => $event->confidence,
                ...$event->metadata,
            ],
        );
    }
}
