<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpResponsePayload;

#[CoversClass(HttpResponsePayload::class)]
final class HttpResponsePayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsHttpResponse(): void
    {
        self::assertSame(EventType::HttpResponse, $this->createPayload()->eventType());
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

        self::assertSame(200, $array['status_code']);
        self::assertSame(12.5, $array['duration_ms']);
        self::assertSame(['content-type' => 'application/json'], $array['headers']);
        self::assertSame(1024, $array['content_length']);
        self::assertSame('application/json', $array['content_type']);
        self::assertSame('api.users.index', $array['route_name']);
        self::assertNull($array['body_preview']);
    }

    #[Test]
    public function bodyPreviewIncludedWhenProvided(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 500,
            durationMs: 50.0,
            headers: [],
            contentLength: 200,
            contentType: 'text/html',
            routeName: null,
            bodyPreview: '<html>Error</html>',
        );

        self::assertSame('<html>Error</html>', $payload->toArray()['body_preview']);
        self::assertNull($payload->routeName);
    }

    #[Test]
    public function nullableFieldsDefaultCorrectly(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 204,
            durationMs: 1.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        $array = $payload->toArray();

        self::assertNull($array['content_length']);
        self::assertNull($array['content_type']);
        self::assertNull($array['route_name']);
        self::assertNull($array['body_preview']);
    }

    private function createPayload(): HttpResponsePayload
    {
        return new HttpResponsePayload(
            statusCode: 200,
            durationMs: 12.5,
            headers: ['content-type' => 'application/json'],
            contentLength: 1024,
            contentType: 'application/json',
            routeName: 'api.users.index',
        );
    }
}
