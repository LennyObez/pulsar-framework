<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Cardinality limiting configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CardinalityConfig
{
    public function __construct(
        public int $maxAttributeKeys = 1000,
        public int $maxMetricSeries = 2000,
        public bool $normalizeUrls = true,
    ) {}

    /**
     * @param array{
     *     max_attribute_keys?: int,
     *     max_metric_series?: int,
     *     normalize_urls?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxAttributeKeys: Coerce::int($data['max_attribute_keys'] ?? null, 1000),
            maxMetricSeries: Coerce::int($data['max_metric_series'] ?? null, 2000),
            normalizeUrls: Coerce::strictBool($data['normalize_urls'] ?? null, true),
        );
    }
}
