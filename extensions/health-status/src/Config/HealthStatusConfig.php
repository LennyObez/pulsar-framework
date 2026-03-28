<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level configuration DTO for the health-status extension.
 *
 * Loaded from config/health-status.php during extension registration.
 * All values have sensible defaults for typical deployments.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthStatusConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $routePrefix = '/_pulsar/status',
        public int $snapshotIntervalSeconds = 60,
        public HistoryRetentionConfig $retention = new HistoryRetentionConfig(),
        public GitHubIntegrityConfig $github = new GitHubIntegrityConfig(),
        public bool $requireAuth = true,
        public bool $publicSummary = false,
        public int $rateLimitPerMinute = 30,
        public int $incidentThresholdConsecutiveFailures = 3,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     route_prefix?: string,
     *     snapshot_interval_seconds?: int,
     *     retention?: array<string, mixed>,
     *     github?: array<string, mixed>,
     *     require_auth?: bool|int|string,
     *     public_summary?: bool|int|string,
     *     rate_limit_per_minute?: int,
     *     incident_threshold_consecutive_failures?: int,
     * } $data Raw configuration array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            routePrefix: $data['route_prefix'] ?? '/_pulsar/status',
            snapshotIntervalSeconds: $data['snapshot_interval_seconds'] ?? 60,
            retention: HistoryRetentionConfig::fromArray($data['retention'] ?? []),
            github: GitHubIntegrityConfig::fromArray($data['github'] ?? []),
            requireAuth: (bool) ($data['require_auth'] ?? true),
            publicSummary: (bool) ($data['public_summary'] ?? false),
            rateLimitPerMinute: $data['rate_limit_per_minute'] ?? 30,
            incidentThresholdConsecutiveFailures: $data['incident_threshold_consecutive_failures'] ?? 3,
        );
    }
}
