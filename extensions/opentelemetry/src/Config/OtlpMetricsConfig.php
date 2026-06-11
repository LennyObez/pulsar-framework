<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            enabled: Coerce::strictBool($data['enabled'] ?? null, true),
            endpoint: Coerce::string($data['endpoint'] ?? null),
            collectIntervalMs: Coerce::int($data['collect_interval_ms'] ?? null, 60_000),
        );
    }
}
