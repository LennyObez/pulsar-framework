<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkRunPayload;

#[CoversClass(BenchmarkRunPayload::class)]
final class BenchmarkRunPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsBenchmarkRun(): void
    {
        self::assertSame(EventType::BenchmarkRun, $this->createPayload()->eventType());
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

        self::assertSame('run-abc', $array['run_id']);
        self::assertSame('8.5.0', $array['php_version']);
        self::assertSame('cli', $array['php_sapi']);
        self::assertSame('Linux', $array['os_platform']);
        self::assertSame('x86_64', $array['os_arch']);
        self::assertSame(3, $array['profile_count']);
        self::assertSame(2, $array['success_count']);
        self::assertSame(1, $array['failure_count']);
        self::assertSame(0, $array['skipped_count']);
        self::assertSame(1234.56, $array['total_duration_ms']);
        self::assertSame(['cold-boot', 'warm-boot', 'api-stress'], $array['profile_names']);
    }

    #[Test]
    public function propertiesAreReadable(): void
    {
        $payload = $this->createPayload();

        self::assertSame('run-abc', $payload->runId);
        self::assertSame('8.5.0', $payload->phpVersion);
        self::assertSame(3, $payload->profileCount);
        self::assertSame(['cold-boot', 'warm-boot', 'api-stress'], $payload->profileNames);
    }

    #[Test]
    public function emptyProfileNames(): void
    {
        $payload = new BenchmarkRunPayload(
            runId: 'run-empty',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'Darwin',
            osArch: 'arm64',
            profileCount: 0,
            successCount: 0,
            failureCount: 0,
            skippedCount: 0,
            totalDurationMs: 0.0,
            profileNames: [],
        );

        self::assertSame([], $payload->toArray()['profile_names']);
        self::assertSame(0, $payload->profileCount);
    }

    private function createPayload(): BenchmarkRunPayload
    {
        return new BenchmarkRunPayload(
            runId: 'run-abc',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'Linux',
            osArch: 'x86_64',
            profileCount: 3,
            successCount: 2,
            failureCount: 1,
            skippedCount: 0,
            totalDurationMs: 1234.56,
            profileNames: ['cold-boot', 'warm-boot', 'api-stress'],
        );
    }
}
