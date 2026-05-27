<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for health check history retention.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HistoryRetentionConfig
{
    public function __construct(
        public int $maxAgeDays = 30,
        public int $maxRows = 100_000,
        public int $cleanupIntervalHours = 6,
    ) {}

    /**
     * @param array{
     *     max_age_days?: int,
     *     max_rows?: int,
     *     cleanup_interval_hours?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxAgeDays: Coerce::int($data['max_age_days'] ?? null, 30),
            maxRows: Coerce::int($data['max_rows'] ?? null, 100_000),
            cleanupIntervalHours: Coerce::int($data['cleanup_interval_hours'] ?? null, 6),
        );
    }
}
