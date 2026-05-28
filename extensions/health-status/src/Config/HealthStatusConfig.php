<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
        $retention = $data['retention'] ?? null;
        $github = $data['github'] ?? null;

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            routePrefix: Coerce::string($data['route_prefix'] ?? null, '/_pulsar/status'),
            snapshotIntervalSeconds: Coerce::int($data['snapshot_interval_seconds'] ?? null, 60),
            retention: HistoryRetentionConfig::fromArray(is_array($retention) ? $retention : []),
            github: GitHubIntegrityConfig::fromArray(is_array($github) ? $github : []),
            requireAuth: (bool) ($data['require_auth'] ?? true),
            publicSummary: (bool) ($data['public_summary'] ?? false),
            rateLimitPerMinute: Coerce::int($data['rate_limit_per_minute'] ?? null, 30),
            incidentThresholdConsecutiveFailures: Coerce::int($data['incident_threshold_consecutive_failures'] ?? null, 3),
        );
    }
}
