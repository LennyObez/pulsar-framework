<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;

#[CoversClass(HttpRequestPayload::class)]
final class HttpRequestPayloadTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/api/users?page=1',
            path: '/api/users',
            headers: ['Host' => 'localhost'],
            clientIp: '127.0.0.1',
            userAgent: 'Test/1.0',
            contentType: 'application/json',
            contentLength: 100,
            routeName: 'api.users.list',
            queryString: 'page=1',
            bodyPreview: '{"filter":"active"}',
        );

        self::assertSame('GET', $payload->method);
        self::assertSame('/api/users?page=1', $payload->uri);
        self::assertSame('/api/users', $payload->path);
        self::assertSame('page=1', $payload->queryString);
        self::assertSame('{"filter":"active"}', $payload->bodyPreview);
    }

    #[Test]
    public function queryStringAndBodyPreviewDefaultToNull(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/',
            path: '/',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        self::assertNull($payload->queryString);
        self::assertNull($payload->bodyPreview);
    }

    #[Test]
    public function eventTypeReturnsHttpRequest(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/',
            path: '/',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        self::assertSame(EventType::HttpRequest, $payload->eventType());
        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesNewFields(): void
    {
        $payload = new HttpRequestPayload(
            method: 'POST',
            uri: '/search?q=test',
            path: '/search',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: 'application/json',
            contentLength: 42,
            routeName: null,
            queryString: 'q=test',
            bodyPreview: '{"q":"test"}',
        );

        $array = $payload->toArray();

        self::assertSame('q=test', $array['query_string']);
        self::assertSame('{"q":"test"}', $array['body_preview']);
    }
}
