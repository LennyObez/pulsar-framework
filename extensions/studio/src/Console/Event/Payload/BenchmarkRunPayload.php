<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Benchmark run summary event payload — one per benchmark run.
 */
#[Internal]
final readonly class BenchmarkRunPayload implements ConsoleEvent
{
    /**
     * @param list<string> $profileNames
     */
    public function __construct(
        public string $runId,
        public string $phpVersion,
        public string $phpSapi,
        public string $osPlatform,
        public string $osArch,
        public int $profileCount,
        public int $successCount,
        public int $failureCount,
        public int $skippedCount,
        public float $totalDurationMs,
        public array $profileNames,
    ) {}

    public function eventType(): EventType
    {
        return EventType::BenchmarkRun;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'php_version' => $this->phpVersion,
            'php_sapi' => $this->phpSapi,
            'os_platform' => $this->osPlatform,
            'os_arch' => $this->osArch,
            'profile_count' => $this->profileCount,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'skipped_count' => $this->skippedCount,
            'total_duration_ms' => $this->totalDurationMs,
            'profile_names' => $this->profileNames,
        ];
    }
}
