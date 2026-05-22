<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extensibility\ExtensionCapability;

use function array_key_exists;
use function count;
use function hrtime;
use function memory_get_usage;
use function sprintf;

/**
 * Monitors runtime behavior of extensions for security and compliance.
 *
 * Tracks capability usage, error rates, and resource consumption per
 * extension. Flags anomalous behavior (excessive errors, capability
 * probing, unexpected resource usage) and reports via the logger.
 */
#[Internal]
final class ExtensionBehaviorMonitor
{
    /** @var array<string, ExtensionBehaviorRecord> */
    private array $records = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $errorRateThreshold = 50,
        private readonly int $capabilityDenialThreshold = 10,
        private readonly int $memoryThresholdBytes = 52_428_800,
    ) {}

    /**
     * Record that an extension used a capability.
     */
    public function recordCapabilityUsage(string $extensionName, ExtensionCapability $capability): void
    {
        $record = $this->getOrCreate($extensionName);
        $key = $capability->name;

        $record->capabilityUsage[$key] = ($record->capabilityUsage[$key] ?? 0) + 1;
    }

    /**
     * Record that an extension was denied a capability.
     */
    public function recordCapabilityDenial(string $extensionName, ExtensionCapability $capability): void
    {
        $record = $this->getOrCreate($extensionName);
        $key = $capability->name;

        $record->capabilityDenials[$key] = ($record->capabilityDenials[$key] ?? 0) + 1;
        $totalDenials = $this->totalDenials($record);

        if ($totalDenials >= $this->capabilityDenialThreshold) {
            $this->logger->warning(
                sprintf(
                    'Extension "%s" has %d capability denials: possible capability probing',
                    $extensionName,
                    $totalDenials,
                ),
                [
                    'extension' => $extensionName,
                    'denials' => $record->capabilityDenials,
                ],
            );
        }
    }

    /**
     * Record an error from an extension.
     */
    public function recordError(string $extensionName, string $errorType): void
    {
        $record = $this->getOrCreate($extensionName);
        $record->errorCount++;
        $record->errorTypes[$errorType] = ($record->errorTypes[$errorType] ?? 0) + 1;

        if ($record->errorCount >= $this->errorRateThreshold) {
            $this->logger->error(
                sprintf(
                    'Extension "%s" has reached %d errors: possible malfunction',
                    $extensionName,
                    $record->errorCount,
                ),
                [
                    'extension' => $extensionName,
                    'error_types' => $record->errorTypes,
                ],
            );
        }
    }

    /**
     * Start timing an extension operation.
     *
     * @return int Nanosecond timestamp for pairing with stopTiming()
     */
    public function startTiming(string $extensionName): int
    {
        $this->getOrCreate($extensionName);

        return hrtime(true);
    }

    /**
     * Stop timing and record the duration.
     *
     * @param int $startNs Nanosecond timestamp from startTiming()
     */
    public function stopTiming(string $extensionName, int $startNs): void
    {
        $record = $this->getOrCreate($extensionName);
        $durationNs = hrtime(true) - $startNs;
        $durationMs = $durationNs / 1_000_000.0;

        $record->totalTimeMs += $durationMs;
        $record->invocationCount++;

        if ($durationMs > $record->maxTimeMs) {
            $record->maxTimeMs = $durationMs;
        }
    }

    /**
     * Record memory usage snapshot for an extension.
     */
    public function recordMemoryUsage(string $extensionName): void
    {
        $record = $this->getOrCreate($extensionName);
        $currentBytes = memory_get_usage();

        if ($currentBytes > $record->peakMemoryBytes) {
            $record->peakMemoryBytes = $currentBytes;
        }

        if ($currentBytes >= $this->memoryThresholdBytes && !$record->memoryWarningIssued) {
            $record->memoryWarningIssued = true;

            $this->logger->warning(
                sprintf(
                    'Extension "%s" memory usage (%s MB) exceeds threshold (%s MB)',
                    $extensionName,
                    number_format($currentBytes / 1_048_576, 1),
                    number_format($this->memoryThresholdBytes / 1_048_576, 1),
                ),
                [
                    'extension' => $extensionName,
                    'memory_bytes' => $currentBytes,
                    'threshold_bytes' => $this->memoryThresholdBytes,
                ],
            );
        }
    }

    /**
     * Get the behavior record for a specific extension.
     */
    public function getRecord(string $extensionName): ?ExtensionBehaviorRecord
    {
        return $this->records[$extensionName] ?? null;
    }

    /**
     * Get all behavior records.
     *
     * @return array<string, ExtensionBehaviorRecord>
     */
    public function allRecords(): array
    {
        return $this->records;
    }

    /**
     * Get a summary report for all monitored extensions.
     *
     * @return list<array{extension: string, invocations: int, errors: int, total_time_ms: float, denials: int, trust_score: float}>
     */
    public function summary(): array
    {
        $result = [];

        foreach ($this->records as $name => $record) {
            $totalDenials = $this->totalDenials($record);
            $trustScore = $this->calculateTrustScore($record);

            $result[] = [
                'extension' => $name,
                'invocations' => $record->invocationCount,
                'errors' => $record->errorCount,
                'total_time_ms' => round($record->totalTimeMs, 2),
                'denials' => $totalDenials,
                'trust_score' => round($trustScore, 2),
            ];
        }

        return $result;
    }

    /**
     * Calculate a trust score (0.0 = untrusted, 1.0 = fully trusted).
     *
     * Based on error rate and capability denial ratio.
     */
    private function calculateTrustScore(ExtensionBehaviorRecord $record): float
    {
        $score = 1.0;

        // Penalize error rate
        if ($record->invocationCount > 0) {
            $errorRate = $record->errorCount / $record->invocationCount;
            $score -= $errorRate * 0.5;
        }

        // Penalize capability probing
        $totalDenials = $this->totalDenials($record);
        $totalUsage = $this->totalCapabilityUsage($record);
        $totalAttempts = $totalDenials + $totalUsage;

        if ($totalAttempts > 0) {
            $denialRate = $totalDenials / $totalAttempts;
            $score -= $denialRate * 0.3;
        }

        // Penalize excessive error count directly
        if ($record->errorCount >= $this->errorRateThreshold) {
            $score -= 0.2;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Reset all behavior records.
     */
    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * Check if an extension is flagged as potentially malicious.
     *
     * An extension is flagged if its trust score drops below 0.5 or
     * if it has exceeded the capability denial threshold.
     */
    public function isFlagged(string $extensionName): bool
    {
        $record = $this->records[$extensionName] ?? null;

        if ($record === null) {
            return false;
        }

        $totalDenials = $this->totalDenials($record);

        if ($totalDenials >= $this->capabilityDenialThreshold) {
            return true;
        }

        return $this->calculateTrustScore($record) < 0.5;
    }

    private function getOrCreate(string $extensionName): ExtensionBehaviorRecord
    {
        if (!array_key_exists($extensionName, $this->records)) {
            $this->records[$extensionName] = new ExtensionBehaviorRecord();
        }

        return $this->records[$extensionName];
    }

    private function totalDenials(ExtensionBehaviorRecord $record): int
    {
        $total = 0;
        foreach ($record->capabilityDenials as $count) {
            $total += $count;
        }

        return $total;
    }

    private function totalCapabilityUsage(ExtensionBehaviorRecord $record): int
    {
        $total = 0;
        foreach ($record->capabilityUsage as $count) {
            $total += $count;
        }

        return $total;
    }
}
