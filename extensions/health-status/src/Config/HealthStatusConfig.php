<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * Top-level configuration DTO for the health-status extension.
 *
 * Loaded from config/health-status.php during extension registration.
 * All values have sensible defaults for typical deployments.
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
     * @param array<string, mixed> $data Raw configuration array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var bool|string $enabled */
        $enabled = $data['enabled'] ?? true;
        /** @var string $routePrefix */
        $routePrefix = $data['route_prefix'] ?? '/_pulsar/status';
        /** @var int|string $snapshotInterval */
        $snapshotInterval = $data['snapshot_interval_seconds'] ?? 60;
        /** @var bool|string $requireAuth */
        $requireAuth = $data['require_auth'] ?? true;
        /** @var bool|string $publicSummary */
        $publicSummary = $data['public_summary'] ?? false;
        /** @var int|string $rateLimit */
        $rateLimit = $data['rate_limit_per_minute'] ?? 30;
        /** @var int|string $incidentThreshold */
        $incidentThreshold = $data['incident_threshold_consecutive_failures'] ?? 3;

        return new self(
            enabled: (bool) $enabled,
            routePrefix: (string) $routePrefix,
            snapshotIntervalSeconds: (int) $snapshotInterval,
            retention: HistoryRetentionConfig::fromArray(self::extractSubArray($data, 'retention')),
            github: GitHubIntegrityConfig::fromArray(self::extractSubArray($data, 'github')),
            requireAuth: (bool) $requireAuth,
            publicSummary: (bool) $publicSummary,
            rateLimitPerMinute: (int) $rateLimit,
            incidentThresholdConsecutiveFailures: (int) $incidentThreshold,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function extractSubArray(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
