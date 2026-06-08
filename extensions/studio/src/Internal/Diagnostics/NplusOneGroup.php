<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

/**
 * Represents a group of repeated queries sharing the same SQL fingerprint.
 */
#[Internal]
final readonly class NplusOneGroup
{
    public function __construct(
        public string $fingerprint,
        public string $normalizedSql,
        public int $count,
        public float $totalDurationMs,
    ) {}

    /**
     * Average duration per execution in milliseconds.
     */
    public function averageDurationMs(): float
    {
        if ($this->count === 0) {
            return 0.0;
        }

        return $this->totalDurationMs / (float) $this->count;
    }
}
