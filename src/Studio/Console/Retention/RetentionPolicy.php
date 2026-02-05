<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Retention;

use Pulsar\Api\Internal;

/**
 * Defines the retention policy for Studio events.
 */
#[Internal]
final readonly class RetentionPolicy
{
    public function __construct(
        public int $maxAgeDays = 7,
        public int $maxSizeMb = 500,
        public int $vacuumIntervalHours = 24,
    ) {}

    /**
     * Get the cutoff timestamp in microseconds for age-based retention.
     */
    public function ageCutoffUs(): int
    {
        return (int) ((microtime(true) - (float) ($this->maxAgeDays * 86400)) * 1_000_000.0);
    }

    /**
     * Get the maximum storage size in bytes.
     */
    public function maxSizeBytes(): int
    {
        return $this->maxSizeMb * 1024 * 1024;
    }
}
