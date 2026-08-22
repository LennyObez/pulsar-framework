<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeRequestCompletePayload;

#[CoversClass(RuntimeRequestCompletePayload::class)]
final class RuntimeRequestCompletePayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsRuntimeRequestComplete(): void
    {
        self::assertSame(EventType::RuntimeRequestComplete, $this->createPayload()->eventType());
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

        self::assertSame('GET', $array['method']);
        self::assertSame('/api/users', $array['path']);
        self::assertSame(200, $array['status_code']);
        self::assertSame(12.5, $array['duration_ms']);
        self::assertSame(2048, $array['memory_delta_bytes']);
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $payload = $this->createPayload();

        self::assertSame('GET', $payload->method);
        self::assertSame('/api/users', $payload->path);
        self::assertSame(200, $payload->statusCode);
        self::assertSame(12.5, $payload->durationMs);
        self::assertSame(2048, $payload->memoryDeltaBytes);
    }

    #[Test]
    public function negativeMemoryDeltaIsAllowed(): void
    {
        $payload = new RuntimeRequestCompletePayload(
            method: 'POST',
            path: '/gc',
            statusCode: 204,
            durationMs: 1.0,
            memoryDeltaBytes: -512,
        );

        self::assertSame(-512, $payload->toArray()['memory_delta_bytes']);
    }

    private function createPayload(): RuntimeRequestCompletePayload
    {
        return new RuntimeRequestCompletePayload(
            method: 'GET',
            path: '/api/users',
            statusCode: 200,
            durationMs: 12.5,
            memoryDeltaBytes: 2048,
        );
    }
}
