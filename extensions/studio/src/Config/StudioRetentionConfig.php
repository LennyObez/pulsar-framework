<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

use function is_int;

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
        if ($retentionEnv !== null) {
            $maxAgeDays = (int) $retentionEnv;
        } else {
            $rawMaxAgeDays = $data['max_age_days'] ?? 7;
            $maxAgeDays = is_int($rawMaxAgeDays) ? $rawMaxAgeDays : (int) (is_numeric($rawMaxAgeDays) ? $rawMaxAgeDays : 7);
        }

        $rawMaxSizeMb = $data['max_size_mb'] ?? 500;
        $maxSizeMb = is_int($rawMaxSizeMb) ? $rawMaxSizeMb : (int) (is_numeric($rawMaxSizeMb) ? $rawMaxSizeMb : 500);

        $rawVacuumIntervalHours = $data['vacuum_interval_hours'] ?? 24;
        $vacuumIntervalHours = is_int($rawVacuumIntervalHours) ? $rawVacuumIntervalHours : (int) (is_numeric($rawVacuumIntervalHours) ? $rawVacuumIntervalHours : 24);

        return new self(
            maxAgeDays: $maxAgeDays,
            maxSizeMb: $maxSizeMb,
            vacuumIntervalHours: $vacuumIntervalHours,
        );
    }
}
