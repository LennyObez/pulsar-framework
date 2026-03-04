<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
        self::assertSame(EventType::OutgoingHttp, $this->createPayload()->eventType());
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

        self::assertSame('POST', $array['method']);
        self::assertSame('https://api.example.com/webhook', $array['url']);
        self::assertSame(200, $array['status_code']);
        self::assertSame(150.3, $array['duration_ms']);
        self::assertNull($array['error_message']);
    }

    #[Test]
    public function toArrayWithNullStatusCode(): void
    {
        $payload = new OutgoingHttpPayload(
            method: 'GET',
            url: 'https://unreachable.test',
            statusCode: null,
            durationMs: 5000.0,
            errorMessage: 'Connection timed out',
        );

        $array = $payload->toArray();

        self::assertNull($array['status_code']);
        self::assertSame('Connection timed out', $array['error_message']);
        self::assertSame(5000.0, $array['duration_ms']);
    }

    #[Test]
    public function propertiesAreReadable(): void
    {
        $payload = $this->createPayload();

        self::assertSame('POST', $payload->method);
        self::assertSame('https://api.example.com/webhook', $payload->url);
        self::assertSame(200, $payload->statusCode);
        self::assertSame(150.3, $payload->durationMs);
    }

    private function createPayload(): OutgoingHttpPayload
    {
        return new OutgoingHttpPayload(
            method: 'POST',
            url: 'https://api.example.com/webhook',
            statusCode: 200,
            durationMs: 150.3,
        );
    }
}
