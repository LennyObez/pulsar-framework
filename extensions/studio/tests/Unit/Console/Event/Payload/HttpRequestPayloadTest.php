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

    #[Test]
    public function toArrayIncludesStructuredRequestDetails(): void
    {
        $payload = new HttpRequestPayload(
            method: 'GET',
            uri: '/api/users/42',
            path: '/api/users/42',
            headers: ['Host' => 'example.com', 'Accept' => 'application/json'],
            clientIp: '10.0.0.1',
            userAgent: 'TestBot/1.0',
            contentType: 'application/json',
            contentLength: 0,
            routeName: 'api.users.show',
            controllerClass: 'App\\Http\\Controller\\UserController',
            controllerMethod: 'show',
            session: ['user_id' => '***', 'role' => 'admin'],
            middleware: ['auth', 'throttle:60,1', 'cors'],
            routeParams: ['id' => '42'],
        );

        $array = $payload->toArray();

        self::assertSame('App\\Http\\Controller\\UserController', $array['controller_class']);
        self::assertSame('show', $array['controller_method']);
        self::assertSame(['user_id' => '***', 'role' => 'admin'], $array['session']);
        self::assertSame(['auth', 'throttle:60,1', 'cors'], $array['middleware']);
        self::assertSame(['id' => '42'], $array['route_params']);
    }

    #[Test]
    public function structuredFieldsDefaultToEmpty(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertNull($array['controller_class']);
        self::assertNull($array['controller_method']);
        self::assertSame([], $array['session']);
        self::assertSame([], $array['middleware']);
        self::assertSame([], $array['route_params']);
    }

    #[Test]
    public function controllerPropertiesAreAccessible(): void
    {
        $payload = new HttpRequestPayload(
            method: 'POST',
            uri: '/api/posts',
            path: '/api/posts',
            headers: [],
            clientIp: null,
            userAgent: null,
            contentType: null,
            contentLength: null,
            routeName: 'api.posts.create',
            controllerClass: 'App\\Controller\\PostController',
            controllerMethod: 'store',
            middleware: ['auth', 'validate'],
        );

        self::assertSame('App\\Controller\\PostController', $payload->controllerClass);
        self::assertSame('store', $payload->controllerMethod);
        self::assertCount(2, $payload->middleware);
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
