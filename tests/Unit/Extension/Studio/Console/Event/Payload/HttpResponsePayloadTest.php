<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

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
    public function constructWithBodyPreview(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 12.5,
            headers: ['Content-Type' => 'application/json'],
            contentLength: 256,
            contentType: 'application/json',
            routeName: 'api.users.list',
            bodyPreview: '{"data":[]}',
        );

        self::assertSame(200, $payload->statusCode);
        self::assertSame(12.5, $payload->durationMs);
        self::assertSame('{"data":[]}', $payload->bodyPreview);
    }

    #[Test]
    public function bodyPreviewDefaultsToNull(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 204,
            durationMs: 1.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertNull($payload->bodyPreview);
    }

    #[Test]
    public function eventTypeReturnsHttpResponse(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 5.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertSame(EventType::HttpResponse, $payload->eventType());
        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesBodyPreview(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 5.0,
            headers: [],
            contentLength: 100,
            contentType: 'text/html',
            routeName: null,
            bodyPreview: '<html>...',
        );

        $array = $payload->toArray();

        self::assertSame('<html>...', $array['body_preview']);
        self::assertArrayHasKey('body_preview', $array);
    }
}
