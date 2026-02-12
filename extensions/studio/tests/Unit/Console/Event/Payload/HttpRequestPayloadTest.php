<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;

final class HttpRequestPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsHttpRequest(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventType::HttpRequest, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('GET', $array['method']);
        self::assertSame('/api/users', $array['uri']);
        self::assertSame('/api/users', $array['path']);
        self::assertSame(['Host' => 'example.com'], $array['headers']);
        self::assertSame('127.0.0.1', $array['client_ip']);
        self::assertSame('TestBot/1.0', $array['user_agent']);
        self::assertSame('application/json', $array['content_type']);
        self::assertSame(0, $array['content_length']);
        self::assertSame('api.users.index', $array['route_name']);
    }

    #[Test]
    public function toArrayIncludesOptionalFields(): void
    {
        $payload = new HttpRequestPayload(
            method: 'POST',
            uri: '/submit',
            path: '/submit',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
            queryString: 'page=1',
            bodyPreview: '{"key":"val"}',
        );

        $array = $payload->toArray();

        self::assertSame('page=1', $array['query_string']);
        self::assertSame('{"key":"val"}', $array['body_preview']);
    }

    private function createPayload(): HttpRequestPayload
    {
        return new HttpRequestPayload(
            method: 'GET',
            uri: '/api/users',
            path: '/api/users',
            headers: ['Host' => 'example.com'],
            clientIp: '127.0.0.1',
            userAgent: 'TestBot/1.0',
            contentType: 'application/json',
            contentLength: 0,
            routeName: 'api.users.index',
        );
    }
}
