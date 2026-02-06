<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Api;

/**
 * Detects queries that exceed the configured duration threshold.
 */
#[Api(since: '1.0.0')]
interface SlowQueryDetectorInterface
{
    /**
     * Check if a query duration exceeds the slow query threshold.
     */
    public function check(string $sql, float $durationMs): bool;

    /**
     * Get the configured threshold in milliseconds.
     */
    public function getThresholdMs(): float;
}
