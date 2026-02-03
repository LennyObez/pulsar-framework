<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Event\Payload\RuntimeRequestCompletePayload;

#[CoversClass(RuntimeRequestCompletePayload::class)]
final class RuntimeRequestCompletePayloadTest extends TestCase
{
    #[Test]
    public function it_returns_correct_event_type(): void
    {
        $payload = new RuntimeRequestCompletePayload(
            method: 'GET',
            path: '/api/users',
            statusCode: 200,
            durationMs: 12.5,
            memoryDeltaBytes: 1024,
        );

        self::assertSame(EventType::RuntimeRequestComplete, $payload->eventType());
    }

    #[Test]
    public function it_returns_schema_version_v1(): void
    {
        $payload = new RuntimeRequestCompletePayload(
            method: 'POST',
            path: '/api/orders',
            statusCode: 201,
            durationMs: 50.0,
            memoryDeltaBytes: 4096,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $payload = new RuntimeRequestCompletePayload(
            method: 'DELETE',
            path: '/api/items/42',
            statusCode: 204,
            durationMs: 3.14,
            memoryDeltaBytes: -512,
        );

        $array = $payload->toArray();

        self::assertSame('DELETE', $array['method']);
        self::assertSame('/api/items/42', $array['path']);
        self::assertSame(204, $array['status_code']);
        self::assertSame(3.14, $array['duration_ms']);
        self::assertSame(-512, $array['memory_delta_bytes']);
    }
}
