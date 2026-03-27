<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function is_string;

/**
 * Top-level analytics extension configuration DTO.
 *
 * Loaded from config/analytics.php during the preBoot phase.
 * All values have sensible defaults for typical deployments.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AnalyticsConfig
{
    /**
     * @param bool $enabled Whether analytics collection is active
     * @param string $collectionDriver Collection mode: 'direct' (default) or 'queue'
     * @param list<string> $trustedProxies IP addresses of trusted reverse proxies (X-Forwarded-For is only trusted from these)
     * @param PrivacyConfig $privacy Privacy-related settings
     * @param TrackingConfig $tracking Tracker script and endpoint configuration
     * @param RetentionConfig $retention Data retention periods
     * @param RateLimitConfig $rateLimit Rate limiting for the collection endpoint
     */
    public function __construct(
        public bool $enabled = true,
        public string $collectionDriver = 'direct',
        public array $trustedProxies = [],
        public PrivacyConfig $privacy = new PrivacyConfig(),
        public TrackingConfig $tracking = new TrackingConfig(),
        public RetentionConfig $retention = new RetentionConfig(),
        public RateLimitConfig $rateLimit = new RateLimitConfig(),
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     collection?: array{driver?: string},
     *     trusted_proxies?: list<string>,
     *     privacy?: array<string, mixed>,
     *     tracking?: array<string, mixed>,
     *     retention?: array<string, mixed>,
     *     rate_limit?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $collection = $data['collection'] ?? [];
        $proxies = $data['trusted_proxies'] ?? [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            collectionDriver: $collection['driver'] ?? 'direct',
            trustedProxies: array_values(array_filter(
                $proxies,
                static fn(mixed $v): bool => is_string($v) && $v !== '',
            )),
            privacy: PrivacyConfig::fromArray($data['privacy'] ?? []),
            tracking: TrackingConfig::fromArray($data['tracking'] ?? []),
            retention: RetentionConfig::fromArray($data['retention'] ?? []),
            rateLimit: RateLimitConfig::fromArray($data['rate_limit'] ?? []),
        );
    }
}
