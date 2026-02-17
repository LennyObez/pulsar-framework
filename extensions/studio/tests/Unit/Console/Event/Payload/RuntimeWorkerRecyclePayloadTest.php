<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
    public function eventTypeReturnsRuntimeWorkerRecycle(): void
    {
        self::assertSame(EventType::RuntimeWorkerRecycle, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createPayload()->toArray();

        self::assertSame('memory_limit', $array['reason']);
        self::assertSame(1000, $array['request_count']);
        self::assertSame(512, $array['memory_usage_mb']);
        self::assertSame(7200, $array['uptime_seconds']);
    }

    #[Test]
    public function propertiesAreReadable(): void
    {
        $payload = $this->createPayload();

        self::assertSame('memory_limit', $payload->reason);
        self::assertSame(1000, $payload->requestCount);
        self::assertSame(512, $payload->memoryUsageMb);
        self::assertSame(7200, $payload->uptimeSeconds);
    }

    private function createPayload(): RuntimeWorkerRecyclePayload
    {
        return new RuntimeWorkerRecyclePayload(
            reason: 'memory_limit',
            requestCount: 1000,
            memoryUsageMb: 512,
            uptimeSeconds: 7200,
        );
    }
}
