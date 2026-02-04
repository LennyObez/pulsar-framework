<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerStartPayload;

#[CoversClass(RuntimeWorkerStartPayload::class)]
final class RuntimeWorkerStartPayloadTest extends TestCase
{
    #[Test]
    public function it_returns_correct_event_type(): void
    {
        $payload = new RuntimeWorkerStartPayload(
            host: '127.0.0.1',
            port: 8080,
            fiberConcurrency: 0,
            maxRequests: 10000,
            memoryThresholdMb: 256,
            startedAt: 1700000000.0,
        );

        self::assertSame(EventType::RuntimeWorkerStart, $payload->eventType());
    }

    #[Test]
    public function it_returns_schema_version_v1(): void
    {
        $payload = new RuntimeWorkerStartPayload(
            host: '127.0.0.1',
            port: 8080,
            fiberConcurrency: 16,
            maxRequests: 5000,
            memoryThresholdMb: 128,
            startedAt: 1700000000.0,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $payload = new RuntimeWorkerStartPayload(
            host: '0.0.0.0',
            port: 3000,
            fiberConcurrency: 64,
            maxRequests: 20000,
            memoryThresholdMb: 512,
            startedAt: 1700000000.123,
        );

        $array = $payload->toArray();

        self::assertSame('0.0.0.0', $array['host']);
        self::assertSame(3000, $array['port']);
        self::assertSame(64, $array['fiber_concurrency']);
        self::assertSame(20000, $array['max_requests']);
        self::assertSame(512, $array['memory_threshold_mb']);
        self::assertSame(1700000000.123, $array['started_at']);
    }
}
