<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Configurable rule for detecting anomalous audit event patterns.
 *
 * Defines a threshold: if N or more events matching the given event type
 * occur from the same actor within the specified time window, the rule fires.
 */
#[Api(since: '1.0.0')]
final readonly class AnomalyRule
{
    /**
     * @param string     $name          Human-readable rule name
     * @param AuditEvent $event         Event type to monitor
     * @param int        $threshold     Minimum event count to trigger
     * @param int        $windowSeconds Sliding window duration in seconds
     */
    public function __construct(
        public string $name,
        public AuditEvent $event,
        public int $threshold,
        public int $windowSeconds,
    ) {}
}
