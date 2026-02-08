<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkProfilePayload;

#[CoversClass(BenchmarkProfilePayload::class)]
final class BenchmarkProfilePayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsBenchmarkProfile(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventType::BenchmarkProfile, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayReturnsAllFieldsWithSnakeCaseKeys(): void
    {
        $payload = new BenchmarkProfilePayload(
            runId: 'abc123',
            profileName: 'jit-tracing',
            profileDescription: 'OPcache + JIT tracing mode',
            bootUs: 1500,
            warmBootUs: 800,
            p50Us: 25,
            p95Us: 120,
            rps: 40000,
            peakRssKb: 32768,
            memoryUsageKb: 16384,
            opcacheMemoryKb: 8192,
            iterations: 1000,
            jitEnabled: true,
            jitMode: 'tracing',
            preloadEnabled: false,
            optimizeEnabled: false,
        );

        $array = $payload->toArray();

        self::assertSame('abc123', $array['run_id']);
        self::assertSame('jit-tracing', $array['profile_name']);
        self::assertSame('OPcache + JIT tracing mode', $array['profile_description']);
        self::assertSame(1500, $array['boot_us']);
        self::assertSame(800, $array['warm_boot_us']);
        self::assertSame(25, $array['p50_us']);
        self::assertSame(120, $array['p95_us']);
        self::assertSame(40000, $array['rps']);
        self::assertSame(32768, $array['peak_rss_kb']);
        self::assertSame(16384, $array['memory_usage_kb']);
        self::assertSame(8192, $array['opcache_memory_kb']);
        self::assertSame(1000, $array['iterations']);
        self::assertTrue($array['jit_enabled']);
        self::assertSame('tracing', $array['jit_mode']);
        self::assertFalse($array['preload_enabled']);
        self::assertFalse($array['optimize_enabled']);
    }

    #[Test]
    public function toArraySerializesNullOpcacheMemory(): void
    {
        $payload = new BenchmarkProfilePayload(
            runId: 'def456',
            profileName: 'baseline',
            profileDescription: 'No OPcache',
            bootUs: 2000,
            warmBootUs: 1800,
            p50Us: 30,
            p95Us: 150,
            rps: 33000,
            peakRssKb: 24576,
            memoryUsageKb: 12288,
            opcacheMemoryKb: null,
            iterations: 1000,
            jitEnabled: false,
            jitMode: 'off',
            preloadEnabled: false,
            optimizeEnabled: false,
        );

        $array = $payload->toArray();

        self::assertNull($array['opcache_memory_kb']);
    }

    #[Test]
    public function toArraySerializesOptimizeEnabled(): void
    {
        $payload = new BenchmarkProfilePayload(
            runId: 'opt-run',
            profileName: 'baseline-optimized',
            profileDescription: 'OPcache + framework cache',
            bootUs: 900,
            warmBootUs: 400,
            p50Us: 15,
            p95Us: 80,
            rps: 60000,
            peakRssKb: 16384,
            memoryUsageKb: 8192,
            opcacheMemoryKb: 4096,
            iterations: 1000,
            jitEnabled: false,
            jitMode: 'off',
            preloadEnabled: false,
            optimizeEnabled: true,
        );

        $array = $payload->toArray();

        self::assertTrue($array['optimize_enabled']);
    }

    private function createPayload(): BenchmarkProfilePayload
    {
        return new BenchmarkProfilePayload(
            runId: 'test-run',
            profileName: 'baseline',
            profileDescription: 'Test profile',
            bootUs: 1000,
            warmBootUs: 500,
            p50Us: 20,
            p95Us: 100,
            rps: 50000,
            peakRssKb: 16384,
            memoryUsageKb: 8192,
            opcacheMemoryKb: 4096,
            iterations: 1000,
            jitEnabled: false,
            jitMode: 'off',
            preloadEnabled: false,
            optimizeEnabled: false,
        );
    }
}
