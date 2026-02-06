<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Event;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

/**
 * Dispatched when a signal provider detects anomalous behavior.
 *
 * Anomalies include sudden location changes, unusual access times,
 * device fingerprint mismatches, and behavioral deviations. Listeners
 * can trigger alerts, force re-authentication, or adjust trust scores.
 */
#[Api(since: '1.0.0')]
readonly class AnomalyDetectedEvent
{
    /**
     * @param ClaimSource $source Signal source that detected the anomaly
     * @param string $anomalyType Machine-readable anomaly classifier (e.g., "location_jump", "device_mismatch")
     * @param string $description Human-readable description of the anomaly
     * @param string $identityId Affected identity
     * @param string $sessionId Affected session
     * @param float $severity Anomaly severity (0.0 = low, 1.0 = critical)
     * @param array<string, mixed> $details Additional anomaly details for investigation
     */
    public function __construct(
        public ClaimSource $source,
        public string $anomalyType,
        public string $description,
        public string $identityId,
        public string $sessionId,
        public float $severity,
        public array $details = [],
    ) {}
}
