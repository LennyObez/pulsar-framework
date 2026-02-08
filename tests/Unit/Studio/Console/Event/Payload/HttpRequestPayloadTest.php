<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;

#[CoversClass(HttpRequestPayload::class)]
final class HttpRequestPayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/users',
            path: '/users',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function eventTypeReturnsHttpRequest(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/users',
            path: '/users',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        self::assertSame(EventType::HttpRequest, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/users',
            path: '/users',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer token'];

        $payload = new HttpRequestPayload(
            method: 'POST',
            uri: '/api/users?page=1',
            path: '/api/users',
            headers: $headers,
            clientIp: '192.168.1.100',
            userAgent: 'Mozilla/5.0',
            contentType: 'application/json',
            contentLength: 1024,
            routeName: 'users.create',
        );

        self::assertSame('POST', $payload->method);
        self::assertSame('/api/users?page=1', $payload->uri);
        self::assertSame('/api/users', $payload->path);
        self::assertSame($headers, $payload->headers);
        self::assertSame('192.168.1.100', $payload->clientIp);
        self::assertSame('Mozilla/5.0', $payload->userAgent);
        self::assertSame('application/json', $payload->contentType);
        self::assertSame(1024, $payload->contentLength);
        self::assertSame('users.create', $payload->routeName);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $headers = ['Content-Type' => 'application/json'];

        $payload = new HttpRequestPayload(
            method: 'PUT',
            uri: '/api/resource/123',
            path: '/api/resource/123',
            headers: $headers,
            clientIp: '10.0.0.1',
            userAgent: 'TestAgent/1.0',
            contentType: 'application/json',
            contentLength: 512,
            routeName: 'resource.update',
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('method', $array);
        self::assertArrayHasKey('uri', $array);
        self::assertArrayHasKey('path', $array);
        self::assertArrayHasKey('headers', $array);
        self::assertArrayHasKey('client_ip', $array);
        self::assertArrayHasKey('user_agent', $array);
        self::assertArrayHasKey('content_type', $array);
        self::assertArrayHasKey('content_length', $array);
        self::assertArrayHasKey('route_name', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $headers = ['X-Custom-Header' => 'custom-value'];

        $payload = new HttpRequestPayload(
            method: 'DELETE',
            uri: '/api/items/42',
            path: '/api/items/42',
            headers: $headers,
            clientIp: '172.16.0.50',
            userAgent: 'CustomClient/2.0',
            contentType: null,
            contentLength: null,
            routeName: 'items.delete',
        );

        $array = $payload->toArray();

        self::assertSame('DELETE', $array['method']);
        self::assertSame('/api/items/42', $array['uri']);
        self::assertSame('/api/items/42', $array['path']);
        self::assertSame($headers, $array['headers']);
        self::assertSame('172.16.0.50', $array['client_ip']);
        self::assertSame('CustomClient/2.0', $array['user_agent']);
        self::assertNull($array['content_type']);
        self::assertNull($array['content_length']);
        self::assertSame('items.delete', $array['route_name']);
    }

    #[Test]
    public function toArrayHandlesNullableFields(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/health',
            path: '/health',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: null,
        );

        $array = $payload->toArray();

        self::assertNull($array['client_ip']);
        self::assertNull($array['user_agent']);
        self::assertNull($array['content_type']);
        self::assertNull($array['content_length']);
        self::assertNull($array['route_name']);
    }

    #[Test]
    public function toArrayHandlesEmptyHeaders(): void
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

        $array = $payload->toArray();

        self::assertSame([], $array['headers']);
    }
}
