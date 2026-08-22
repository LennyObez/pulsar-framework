<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Contract for individual threat detectors.
 *
 * Each detector analyzes incoming requests for a specific attack pattern
 * and returns a ThreatEvent when a threat is detected.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThreatDetectorInterface
{
    /**
     * Analyze a request for threats.
     *
     * Returns a ThreatEvent if a threat is detected, null otherwise.
     * Implementations should be fast (< 1ms) for the common non-threat case.
     */
    public function analyze(ServerRequestInterface $request): ?ThreatEvent;

    /**
     * Record a security-relevant event for correlation.
     *
     * Called by the engine to feed events (e.g., failed logins) into
     * detectors for pattern analysis. Detectors that don't need
     * event correlation may implement this as a no-op.
     *
     * @param array<string, mixed> $context
     */
    public function recordEvent(string $eventType, array $context): void;
}
