<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

use function array_filter;
use function array_values;
use function count;
use function microtime;
use function sprintf;

/**
 * Sliding-window anomaly detector for audit events.
 *
 * Tracks audit event occurrences per actor and event type. When an actor
 * produces N or more events of a given type within the configured time
 * window, the matching rule fires and an incident is reported.
 *
 * Thread-safe within a single process (cooperative scheduling via Fibers).
 * @api
 */
#[Api(since: '1.0.0')]
final class AuditAnomalyDetector
{
    /** @var list<AnomalyRule> */
    private array $rules = [];

    /**
     * Recorded events: keyed by "{actor}:{event_type}" => list of timestamps.
     *
     * @var array<string, list<float>>
     */
    private array $events = [];

    /** @var list<AnomalyDetection> */
    private array $detections = [];

    public function __construct(
        private readonly ?IncidentReporterInterface $incidentReporter = null,
    ) {}

    /**
     * Register an anomaly detection rule.
     */
    public function addRule(AnomalyRule $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * Record an audit event and evaluate all matching rules.
     *
     * @return list<AnomalyDetection> Detections triggered by this event (empty if none fired)
     */
    public function record(AuditEvent $event, string $actor): array
    {
        $now = microtime(true);
        $key = $actor . ':' . $event->value;

        $this->events[$key][] = $now;

        $triggered = [];

        foreach ($this->rules as $rule) {
            if ($rule->event !== $event) {
                continue;
            }

            $this->pruneWindow($key, $now, $rule->windowSeconds);

            $eventCount = count($this->events[$key] ?? []);

            if ($eventCount >= $rule->threshold) {
                $detection = AnomalyDetection::fromRule($rule, $actor, $eventCount);
                $this->detections[] = $detection;
                $triggered[] = $detection;

                $this->incidentReporter?->report(
                    severity: IncidentSeverity::High,
                    title: sprintf('Audit anomaly: %s', $rule->name),
                    description: sprintf(
                        'Actor "%s" triggered %d "%s" events in %d seconds (threshold: %d)',
                        $actor,
                        $eventCount,
                        $event->value,
                        $rule->windowSeconds,
                        $rule->threshold,
                    ),
                    source: 'audit_anomaly_detector',
                    metadata: [
                        'rule' => $rule->name,
                        'actor' => $actor,
                        'event_type' => $event->value,
                        'event_count' => $eventCount,
                        'threshold' => $rule->threshold,
                        'window_seconds' => $rule->windowSeconds,
                    ],
                );

                // Clear the window after firing to prevent duplicate alerts
                $this->events[$key] = [];
            }
        }

        return $triggered;
    }

    /**
     * Get all detections that have occurred.
     *
     * @return list<AnomalyDetection>
     */
    public function detections(): array
    {
        return $this->detections;
    }

    /**
     * Get the registered rules.
     *
     * @return list<AnomalyRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Clear all recorded events and detections.
     */
    public function reset(): void
    {
        $this->events = [];
        $this->detections = [];
    }

    /**
     * Prune events outside the sliding window for a specific key.
     */
    private function pruneWindow(string $key, float $now, int $windowSeconds): void
    {
        if (!isset($this->events[$key])) {
            return;
        }

        $threshold = $now - (float) $windowSeconds;

        $this->events[$key] = array_values(array_filter(
            $this->events[$key],
            static fn(float $ts): bool => $ts > $threshold,
        ));

        if ($this->events[$key] === []) {
            unset($this->events[$key]);
        }
    }
}
