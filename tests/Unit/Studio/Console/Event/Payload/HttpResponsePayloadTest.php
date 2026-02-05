<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Event\Payload\HttpResponsePayload;

#[CoversClass(HttpResponsePayload::class)]
final class HttpResponsePayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 50.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function eventTypeReturnsHttpResponse(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 50.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertSame(EventType::HttpResponse, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 50.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc123'];

        $payload = new HttpResponsePayload(
            statusCode: 201,
            durationMs: 125.5,
            headers: $headers,
            contentLength: 2048,
            contentType: 'application/json',
            routeName: 'users.store',
        );

        self::assertSame(201, $payload->statusCode);
        self::assertSame(125.5, $payload->durationMs);
        self::assertSame($headers, $payload->headers);
        self::assertSame(2048, $payload->contentLength);
        self::assertSame('application/json', $payload->contentType);
        self::assertSame('users.store', $payload->routeName);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 404,
            durationMs: 10.0,
            headers: [],
            contentLength: 128,
            contentType: 'application/json',
            routeName: 'users.show',
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('status_code', $array);
        self::assertArrayHasKey('duration_ms', $array);
        self::assertArrayHasKey('headers', $array);
        self::assertArrayHasKey('content_length', $array);
        self::assertArrayHasKey('content_type', $array);
        self::assertArrayHasKey('route_name', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $headers = ['Cache-Control' => 'no-cache'];

        $payload = new HttpResponsePayload(
            statusCode: 500,
            durationMs: 2500.75,
            headers: $headers,
            contentLength: 4096,
            contentType: 'text/html',
            routeName: 'error.handler',
        );

        $array = $payload->toArray();

        self::assertSame(500, $array['status_code']);
        self::assertSame(2500.75, $array['duration_ms']);
        self::assertSame($headers, $array['headers']);
        self::assertSame(4096, $array['content_length']);
        self::assertSame('text/html', $array['content_type']);
        self::assertSame('error.handler', $array['route_name']);
    }

    #[Test]
    public function toArrayHandlesNullableFields(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 204,
            durationMs: 5.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        $array = $payload->toArray();

        self::assertNull($array['content_length']);
        self::assertNull($array['content_type']);
        self::assertNull($array['route_name']);
    }

    #[Test]
    public function toArrayHandlesEmptyHeaders(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 1.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        $array = $payload->toArray();

        self::assertSame([], $array['headers']);
    }

    #[Test]
    public function handlesVariousStatusCodes(): void
    {
        $statusCodes = [200, 201, 204, 301, 302, 400, 401, 403, 404, 500, 502, 503];

        foreach ($statusCodes as $statusCode) {
            $payload = new HttpResponsePayload(
                statusCode: $statusCode,
                durationMs: 10.0,
                headers: [],
                contentLength: null,
                contentType: null,
                routeName: null,
            );

            self::assertSame($statusCode, $payload->statusCode);
            self::assertSame($statusCode, $payload->toArray()['status_code']);
        }
    }

    #[Test]
    public function handlesZeroDuration(): void
    {
        $payload = new HttpResponsePayload(
            statusCode: 200,
            durationMs: 0.0,
            headers: [],
            contentLength: null,
            contentType: null,
            routeName: null,
        );

        self::assertSame(0.0, $payload->durationMs);
        self::assertSame(0.0, $payload->toArray()['duration_ms']);
    }
}
