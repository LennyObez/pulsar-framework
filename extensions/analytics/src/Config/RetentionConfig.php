<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

/**
 * Data retention periods for analytics data.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetentionConfig
{
    /**
     * @param int $rawDays Days to keep raw page view and event data (default: 90)
     * @param int $aggregatedDays Days to keep pre-aggregated daily stats (default: 730 = 2 years)
     * @param int $hourlyHours Hours to keep hourly rollup data (default: 48)
     */
    public function __construct(
        public int $rawDays = 90,
        public int $aggregatedDays = 730,
        public int $hourlyHours = 48,
    ) {}

    /**
     * @param array{
     *     raw_days?: int,
     *     aggregated_days?: int,
     *     hourly_hours?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rawDays: $data['raw_days'] ?? 90,
            aggregatedDays: $data['aggregated_days'] ?? 730,
            hourlyHours: $data['hourly_hours'] ?? 48,
        );
    }
}
