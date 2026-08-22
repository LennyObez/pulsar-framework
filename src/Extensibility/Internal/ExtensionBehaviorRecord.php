<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use Pulsar\Api\Internal;

/**
 * Mutable record tracking an extension's runtime behavior.
 *
 * Used by ExtensionBehaviorMonitor to accumulate capability usage,
 * error counts, timing data, and memory snapshots per extension.
 */
#[Internal]
final class ExtensionBehaviorRecord
{
    /** @var array<string, int> Capability name → usage count */
    public array $capabilityUsage = [];

    /** @var array<string, int> Capability name → denial count */
    public array $capabilityDenials = [];

    /** @var array<string, int> Error type → count */
    public array $errorTypes = [];

    public int $errorCount = 0;

    public int $invocationCount = 0;

    public float $totalTimeMs = 0.0;

    public float $maxTimeMs = 0.0;

    public int $peakMemoryBytes = 0;

    public bool $memoryWarningIssued = false;
}
