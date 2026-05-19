<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

/**
 * Retention policy configuration for Studio event storage.
 */
#[Internal]
final readonly class StudioRetentionConfig
{
    public function __construct(
        public int $maxAgeDays = 7,
        public int $maxSizeMb = 500,
        public int $vacuumIntervalHours = 24,
    ) {}

    /**
     * @param array{
     *     max_age_days?: int,
     *     max_size_mb?: int,
     *     vacuum_interval_hours?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $retentionEnv = $environment->get('STUDIO_RETENTION_DAYS');
        $maxAgeDays = $retentionEnv !== null ? (int) $retentionEnv : ($data['max_age_days'] ?? 7);

        return new self(
            maxAgeDays: $maxAgeDays,
            maxSizeMb: $data['max_size_mb'] ?? 500,
            vacuumIntervalHours: $data['vacuum_interval_hours'] ?? 24,
        );
    }
}
