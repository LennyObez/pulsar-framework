<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
    public function eventTypeReturnsRuntimeWorkerStart(): void
    {
        self::assertSame(EventType::RuntimeWorkerStart, $this->createPayload()->eventType());
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

        self::assertSame('0.0.0.0', $array['host']);
        self::assertSame(8080, $array['port']);
        self::assertSame(16, $array['fiber_concurrency']);
        self::assertSame(10000, $array['max_requests']);
        self::assertSame(256, $array['memory_threshold_mb']);
        self::assertSame(1700000000.0, $array['started_at']);
    }

    #[Test]
    public function propertiesAreReadable(): void
    {
        $payload = $this->createPayload();

        self::assertSame('0.0.0.0', $payload->host);
        self::assertSame(8080, $payload->port);
        self::assertSame(16, $payload->fiberConcurrency);
        self::assertSame(10000, $payload->maxRequests);
        self::assertSame(256, $payload->memoryThresholdMb);
    }

    private function createPayload(): RuntimeWorkerStartPayload
    {
        return new RuntimeWorkerStartPayload(
            host: '0.0.0.0',
            port: 8080,
            fiberConcurrency: 16,
            maxRequests: 10000,
            memoryThresholdMb: 256,
            startedAt: 1700000000.0,
        );
    }
}
