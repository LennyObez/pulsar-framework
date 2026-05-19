<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for connection failover management.
 *
 * Failover handles detection and switching to a standby endpoint.
 * It does NOT handle promotion: that is the responsibility of the
 * database cluster itself.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FailoverConfig
{
    /**
     * @param bool $enabled
     * @param int $failureThreshold
     * @param int $retryIntervalSeconds
     * @param 'dns'|'callback'|'config-reload' $strategy
     * @param bool $complianceEventsEnabled
     */
    public function __construct(
        public bool $enabled = false,
        public int $failureThreshold = 3,
        public int $retryIntervalSeconds = 5,
        public string $strategy = 'dns',
        public bool $complianceEventsEnabled = false,
    ) {}

    /**
     * Build from a raw config array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     failure_threshold?: int,
     *     retry_interval_seconds?: int,
     *     strategy?: 'dns'|'callback'|'config-reload',
     *     compliance_events_enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            failureThreshold: $data['failure_threshold'] ?? 3,
            retryIntervalSeconds: $data['retry_interval_seconds'] ?? 5,
            strategy: $data['strategy'] ?? 'dns',
            complianceEventsEnabled: (bool) ($data['compliance_events_enabled'] ?? false),
        );
    }
}
