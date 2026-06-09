<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $retentionEnv = $environment->get('STUDIO_RETENTION_DAYS');
        $maxAgeDays = $retentionEnv !== null
            ? (int) $retentionEnv
            : Coerce::int($data['max_age_days'] ?? null, 7);

        return new self(
            maxAgeDays: $maxAgeDays,
            maxSizeMb: Coerce::int($data['max_size_mb'] ?? null, 500),
            vacuumIntervalHours: Coerce::int($data['vacuum_interval_hours'] ?? null, 24),
        );
    }
}
