<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Metrics-specific OTLP configuration.
 * @api
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
     * @param array{
     *     enabled?: bool,
     *     endpoint?: string,
     *     collect_interval_ms?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            endpoint: $data['endpoint'] ?? '',
            collectIntervalMs: $data['collect_interval_ms'] ?? 60_000,
        );
    }
}
