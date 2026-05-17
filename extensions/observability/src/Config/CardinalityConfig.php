<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $maxAttributeKeys = $data['max_attribute_keys'] ?? 1000;
        $maxMetricSeries = $data['max_metric_series'] ?? 2000;
        $normalizeUrls = $data['normalize_urls'] ?? true;

        return new self(
            maxAttributeKeys: is_int($maxAttributeKeys) ? $maxAttributeKeys : 1000,
            maxMetricSeries: is_int($maxMetricSeries) ? $maxMetricSeries : 2000,
            normalizeUrls: is_bool($normalizeUrls) ? $normalizeUrls : true,
        );
    }
}
