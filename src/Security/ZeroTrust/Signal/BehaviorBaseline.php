<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Baseline behavioral profile for a subject (user/identity).
 *
 * Tracks typical request rates and activity patterns to enable
 * anomaly detection by the behavior signal provider.
 */
#[Api(since: '1.0.0')]
readonly class BehaviorBaseline
{
    /**
     * @param float $avgRequestsPerMinute Average request rate over the baseline period
     * @param array<string, mixed> $knownPatterns Characteristic access patterns (e.g., typical endpoints, methods)
     * @param DateTimeImmutable|null $lastActivity Timestamp of the subject's most recent activity
     */
    public function __construct(
        public float $avgRequestsPerMinute,
        public array $knownPatterns = [],
        public ?DateTimeImmutable $lastActivity = null,
    ) {}
}
