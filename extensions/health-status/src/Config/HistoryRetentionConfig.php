<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for health check history retention.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int|string $maxAgeDays */
        $maxAgeDays = $data['max_age_days'] ?? 30;
        /** @var int|string $maxRows */
        $maxRows = $data['max_rows'] ?? 100_000;
        /** @var int|string $cleanupIntervalHours */
        $cleanupIntervalHours = $data['cleanup_interval_hours'] ?? 6;

        return new self(
            maxAgeDays: (int) $maxAgeDays,
            maxRows: (int) $maxRows,
            cleanupIntervalHours: (int) $cleanupIntervalHours,
        );
    }
}
