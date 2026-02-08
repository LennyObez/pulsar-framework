<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerRecyclePayload;

#[CoversClass(RuntimeWorkerRecyclePayload::class)]
final class RuntimeWorkerRecyclePayloadTest extends TestCase
{
    #[Test]
    public function it_returns_correct_event_type(): void
    {
        $payload = new RuntimeWorkerRecyclePayload(
            reason: 'max_requests',
            requestCount: 10000,
            memoryUsageMb: 200,
            uptimeSeconds: 3600,
        );

        self::assertSame(EventType::RuntimeWorkerRecycle, $payload->eventType());
    }

    #[Test]
    public function it_returns_schema_version_v1(): void
    {
        $payload = new RuntimeWorkerRecyclePayload(
            reason: 'memory_threshold',
            requestCount: 5000,
            memoryUsageMb: 256,
            uptimeSeconds: 1800,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $payload = new RuntimeWorkerRecyclePayload(
            reason: 'time_limit',
            requestCount: 7500,
            memoryUsageMb: 180,
            uptimeSeconds: 7200,
        );

        $array = $payload->toArray();

        self::assertSame('time_limit', $array['reason']);
        self::assertSame(7500, $array['request_count']);
        self::assertSame(180, $array['memory_usage_mb']);
        self::assertSame(7200, $array['uptime_seconds']);
    }
}
