<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\EdgeResponse;

#[CoversClass(EdgeResponse::class)]
final class EdgeResponseTest extends TestCase
{
    #[Test]
    public function redirect_creates_302_with_location(): void
    {
        $response = EdgeResponse::redirect('https://example.com/new');

        self::assertSame(302, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame('https://example.com/new', $response->headers['Location']);
    }

    #[Test]
    public function redirect_with_custom_status(): void
    {
        $response = EdgeResponse::redirect('/moved', 301);

        self::assertSame(301, $response->statusCode);
        self::assertSame('/moved', $response->headers['Location']);
    }

    #[Test]
    public function html_creates_response_with_content_type(): void
    {
        $response = EdgeResponse::html('<h1>Hello</h1>');

        self::assertSame(200, $response->statusCode);
        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    #[Test]
    public function html_with_custom_status(): void
    {
        $response = EdgeResponse::html('<p>Not Found</p>', 404);

        self::assertSame(404, $response->statusCode);
    }

    #[Test]
    public function json_encodes_data(): void
    {
        $response = EdgeResponse::json(['key' => 'value']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"key":"value"}', $response->body);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    #[Test]
    public function json_with_custom_status(): void
    {
        $response = EdgeResponse::json(['error' => 'bad'], 400);

        self::assertSame(400, $response->statusCode);
    }

    #[Test]
    public function deny_creates_403(): void
    {
        $response = EdgeResponse::deny();

        self::assertSame(403, $response->statusCode);
        self::assertSame('Access denied', $response->body);
        self::assertSame('text/plain', $response->headers['Content-Type']);
    }

    #[Test]
    public function deny_with_custom_reason(): void
    {
        $response = EdgeResponse::deny('Blocked by WAF');

        self::assertSame('Blocked by WAF', $response->body);
    }

    #[Test]
    public function with_header_returns_new_instance(): void
    {
        $original = EdgeResponse::html('<p>ok</p>');
        $modified = $original->withHeader('X-Custom', 'value');

        self::assertNotSame($original, $modified);
        self::assertSame('value', $modified->headers['X-Custom']);
        self::assertArrayNotHasKey('X-Custom', $original->headers);
    }

    #[Test]
    public function with_cookie_returns_new_instance(): void
    {
        $original = EdgeResponse::html('<p>ok</p>');
        $modified = $original->withCookie('session', 'abc');

        self::assertNotSame($original, $modified);
        self::assertSame('abc', $modified->cookies['session']);
        self::assertSame([], $original->cookies);
    }

    #[Test]
    public function chained_with_methods(): void
    {
        $response = EdgeResponse::redirect('/home')
            ->withHeader('X-Edge', 'true')
            ->withCookie('variant', 'a');

        self::assertSame('true', $response->headers['X-Edge']);
        self::assertSame('a', $response->cookies['variant']);
        self::assertSame('/home', $response->headers['Location']);
    }
}
