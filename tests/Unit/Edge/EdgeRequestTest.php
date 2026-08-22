<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\EdgeRequest;

#[CoversClass(EdgeRequest::class)]
final class EdgeRequestTest extends TestCase
{
    #[Test]
    public function constructor_assigns_all_fields(): void
    {
        $request = new EdgeRequest(
            method: 'GET',
            url: 'https://example.com/page',
            path: '/page',
            headers: ['Accept' => 'text/html'],
            cookies: ['session' => 'abc123'],
            geo: ['country' => 'US', 'region' => 'CA'],
            ip: '192.168.1.1',
            userAgent: 'TestBot/1.0',
        );

        self::assertSame('GET', $request->method);
        self::assertSame('https://example.com/page', $request->url);
        self::assertSame('/page', $request->path);
        self::assertSame('192.168.1.1', $request->ip);
        self::assertSame('TestBot/1.0', $request->userAgent);
    }

    #[Test]
    public function defaults_for_optional_fields(): void
    {
        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertSame([], $request->headers);
        self::assertSame([], $request->cookies);
        self::assertSame([], $request->geo);
        self::assertSame('', $request->ip);
        self::assertSame('', $request->userAgent);
    }

    #[Test]
    public function header_returns_value_case_insensitively(): void
    {
        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            headers: ['Content-Type' => 'text/html', 'X-Custom' => 'value'],
        );

        self::assertSame('text/html', $request->header('content-type'));
        self::assertSame('text/html', $request->header('Content-Type'));
        self::assertSame('text/html', $request->header('CONTENT-TYPE'));
        self::assertSame('value', $request->header('x-custom'));
    }

    #[Test]
    public function header_returns_null_for_missing(): void
    {
        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($request->header('X-Missing'));
    }

    #[Test]
    public function cookie_returns_value(): void
    {
        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            cookies: ['token' => 'abc', 'pref' => 'dark'],
        );

        self::assertSame('abc', $request->cookie('token'));
        self::assertSame('dark', $request->cookie('pref'));
    }

    #[Test]
    public function cookie_returns_null_for_missing(): void
    {
        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($request->cookie('missing'));
    }

    #[Test]
    public function geo_returns_value_by_key(): void
    {
        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'DE', 'city' => 'Berlin'],
        );

        self::assertSame('DE', $request->geo('country'));
        self::assertSame('Berlin', $request->geo('city'));
        self::assertNull($request->geo('region'));
    }

    #[Test]
    public function country_shortcut(): void
    {
        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            geo: ['country' => 'FR'],
        );

        self::assertSame('FR', $request->country());
    }

    #[Test]
    public function country_returns_null_without_geo_data(): void
    {
        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($request->country());
    }
}
