<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Event\Payload\BenchmarkRunPayload;

#[CoversClass(BenchmarkRunPayload::class)]
final class BenchmarkRunPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsBenchmarkRun(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventType::BenchmarkRun, $payload->eventType());
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
        $payload = new BenchmarkRunPayload(
            runId: 'run-abc123',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'Linux',
            osArch: 'x86_64',
            profileCount: 6,
            successCount: 5,
            failureCount: 1,
            skippedCount: 0,
            totalDurationMs: 12345.67,
            profileNames: ['baseline', 'jit-function', 'jit-tracing'],
        );

        $array = $payload->toArray();

        self::assertSame('run-abc123', $array['run_id']);
        self::assertSame('8.5.0', $array['php_version']);
        self::assertSame('cli', $array['php_sapi']);
        self::assertSame('Linux', $array['os_platform']);
        self::assertSame('x86_64', $array['os_arch']);
        self::assertSame(6, $array['profile_count']);
        self::assertSame(5, $array['success_count']);
        self::assertSame(1, $array['failure_count']);
        self::assertSame(0, $array['skipped_count']);
        self::assertSame(12345.67, $array['total_duration_ms']);
        self::assertSame(['baseline', 'jit-function', 'jit-tracing'], $array['profile_names']);
    }

    #[Test]
    public function toArrayHandlesEmptyProfileNames(): void
    {
        $payload = new BenchmarkRunPayload(
            runId: 'empty-run',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'Windows',
            osArch: 'x86_64',
            profileCount: 0,
            successCount: 0,
            failureCount: 0,
            skippedCount: 0,
            totalDurationMs: 0.0,
            profileNames: [],
        );

        $array = $payload->toArray();

        self::assertSame([], $array['profile_names']);
        self::assertSame(0, $array['profile_count']);
    }

    private function createPayload(): BenchmarkRunPayload
    {
        return new BenchmarkRunPayload(
            runId: 'test-run',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'Linux',
            osArch: 'x86_64',
            profileCount: 3,
            successCount: 3,
            failureCount: 0,
            skippedCount: 0,
            totalDurationMs: 5000.0,
            profileNames: ['baseline', 'jit-function', 'jit-tracing'],
        );
    }
}
