<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Benchmark profile event payload — one per profile per benchmark run.
 */
#[Internal]
final readonly class BenchmarkProfilePayload implements ConsoleEvent
{
    public function __construct(
        public string $runId,
        public string $profileName,
        public string $profileDescription,
        public int $bootUs,
        public int $warmBootUs,
        public int $p50Us,
        public int $p95Us,
        public int $rps,
        public int $peakRssKb,
        public int $memoryUsageKb,
        public ?int $opcacheMemoryKb,
        public int $iterations,
        public bool $jitEnabled,
        public string $jitMode,
        public bool $preloadEnabled,
        public bool $optimizeEnabled,
    ) {}

    public function eventType(): EventType
    {
        return EventType::BenchmarkProfile;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'profile_name' => $this->profileName,
            'profile_description' => $this->profileDescription,
            'boot_us' => $this->bootUs,
            'warm_boot_us' => $this->warmBootUs,
            'p50_us' => $this->p50Us,
            'p95_us' => $this->p95Us,
            'rps' => $this->rps,
            'peak_rss_kb' => $this->peakRssKb,
            'memory_usage_kb' => $this->memoryUsageKb,
            'opcache_memory_kb' => $this->opcacheMemoryKb,
            'iterations' => $this->iterations,
            'jit_enabled' => $this->jitEnabled,
            'jit_mode' => $this->jitMode,
            'preload_enabled' => $this->preloadEnabled,
            'optimize_enabled' => $this->optimizeEnabled,
        ];
    }
}
