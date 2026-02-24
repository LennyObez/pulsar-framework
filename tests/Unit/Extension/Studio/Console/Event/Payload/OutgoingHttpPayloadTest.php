<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\OutgoingHttpPayload;

#[CoversClass(OutgoingHttpPayload::class)]
final class OutgoingHttpPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsOutgoingHttp(): void
    {
        $payload = new OutgoingHttpPayload(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            durationMs: 150.0,
        );

        self::assertSame(EventType::OutgoingHttp, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new OutgoingHttpPayload(
            method: 'POST',
            url: 'https://api.example.com',
            statusCode: 201,
            durationMs: 50.0,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new OutgoingHttpPayload(
            method: 'POST',
            url: 'https://api.example.com/data',
            statusCode: null,
            durationMs: 30000.0,
            errorMessage: 'Connection refused',
        );

        $data = $payload->toArray();

        self::assertSame('POST', $data['method']);
        self::assertSame('https://api.example.com/data', $data['url']);
        self::assertNull($data['status_code']);
        self::assertSame(30000.0, $data['duration_ms']);
        self::assertSame('Connection refused', $data['error_message']);
    }
}
