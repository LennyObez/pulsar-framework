<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

/**
 * Immutable record of a single slow query detection.
 */
#[Internal]
final readonly class SlowQueryRecord
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $sql,
        public string $fingerprint,
        public float $durationMs,
        public float $thresholdMs,
        public string $queryType,
        public ?string $connectionName,
        public float $recordedAt,
    ) {}

    /**
     * How many times slower than the threshold this query was.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function overageMultiplier(): float
    {
        if ($this->thresholdMs <= 0.0) {
            return 0.0;
        }

        return $this->durationMs / $this->thresholdMs;
    }
}
