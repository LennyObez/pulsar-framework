<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('run-001', $array['run_id']);
        self::assertSame('cold-boot', $array['profile_name']);
        self::assertSame('Cold boot profile', $array['profile_description']);
        self::assertSame(1500, $array['boot_us']);
        self::assertSame(800, $array['warm_boot_us']);
        self::assertSame(500, $array['p50_us']);
        self::assertSame(1200, $array['p95_us']);
        self::assertSame(5000, $array['rps']);
        self::assertSame(32768, $array['peak_rss_kb']);
        self::assertSame(16384, $array['memory_usage_kb']);
        self::assertSame(8192, $array['opcache_memory_kb']);
        self::assertSame(100, $array['iterations']);
        self::assertTrue($array['jit_enabled']);
        self::assertSame('tracing', $array['jit_mode']);
        self::assertFalse($array['preload_enabled']);
        self::assertTrue($array['optimize_enabled']);
    }

    #[Test]
    public function propertiesAreReadable(): void
    {
        $payload = $this->createPayload();

        self::assertSame('run-001', $payload->runId);
        self::assertSame('cold-boot', $payload->profileName);
        self::assertSame(1500, $payload->bootUs);
        self::assertSame(5000, $payload->rps);
    }

    #[Test]
    public function nullOpcacheMemoryKb(): void
    {
        $payload = new BenchmarkProfilePayload(
            runId: 'run-002',
            profileName: 'no-opcache',
            profileDescription: 'Without opcache',
            bootUs: 2000,
            warmBootUs: 1500,
            p50Us: 700,
            p95Us: 1800,
            rps: 3000,
            peakRssKb: 40000,
            memoryUsageKb: 20000,
            opcacheMemoryKb: null,
            iterations: 50,
            jitEnabled: false,
            jitMode: 'off',
            preloadEnabled: false,
            optimizeEnabled: false,
        );

        self::assertNull($payload->opcacheMemoryKb);
        self::assertNull($payload->toArray()['opcache_memory_kb']);
    }

    private function createPayload(): BenchmarkProfilePayload
    {
        return new BenchmarkProfilePayload(
            runId: 'run-001',
            profileName: 'cold-boot',
            profileDescription: 'Cold boot profile',
            bootUs: 1500,
            warmBootUs: 800,
            p50Us: 500,
            p95Us: 1200,
            rps: 5000,
            peakRssKb: 32768,
            memoryUsageKb: 16384,
            opcacheMemoryKb: 8192,
            iterations: 100,
            jitEnabled: true,
            jitMode: 'tracing',
            preloadEnabled: false,
            optimizeEnabled: true,
        );
    }
}
