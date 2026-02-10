<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Metrics-specific OTLP configuration.
 */
#[Api(since: '1.0.0')]
final readonly class OtlpMetricsConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $endpoint = '',
        public int $collectIntervalMs = 60_000,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawEnabled = $data['enabled'] ?? true;
        $rawEndpoint = $data['endpoint'] ?? '';
        $rawInterval = $data['collect_interval_ms'] ?? 60_000;

        return new self(
            enabled: is_bool($rawEnabled) ? $rawEnabled : true,
            endpoint: is_string($rawEndpoint) ? $rawEndpoint : '',
            collectIntervalMs: is_int($rawInterval) ? $rawInterval : 60_000,
        );
    }
}
