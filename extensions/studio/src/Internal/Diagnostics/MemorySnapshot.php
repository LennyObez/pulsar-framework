<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

use function sprintf;

/**
 * Immutable record of memory state at a point in time.
 */
#[Internal]
final readonly class MemorySnapshot
{
    public function __construct(
        public int $usageBytes,
        public int $peakBytes,
        public int $requestNumber,
        public float $timestamp,
    ) {}

    /**
     * Format usage as a human-readable string.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function formattedUsage(): string
    {
        return self::formatBytes($this->usageBytes);
    }

    /**
     * Format peak as a human-readable string.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function formattedPeak(): string
    {
        return self::formatBytes($this->peakBytes);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return sprintf('%.1f KB', (float) $bytes / 1024.0);
        }

        return sprintf('%.1f MB', (float) $bytes / 1048576.0);
    }
}
