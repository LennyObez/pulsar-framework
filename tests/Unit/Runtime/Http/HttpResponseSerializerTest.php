<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Http\HttpResponseSerializer;

#[CoversClass(HttpResponseSerializer::class)]
final class HttpResponseSerializerTest extends TestCase
{
    private HttpResponseSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new HttpResponseSerializer();
    }

    #[Test]
    public function it_serializes_a_basic_response(): void
    {
        $response = new Response(
            statusCode: ResponseStatus::OK->value,
            headers: ['Content-Type' => 'text/plain'],
            body: 'Hello World',
        );

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("HTTP/1.1 200 OK\r\n", $raw);
        self::assertStringContainsString("Content-Type: text/plain\r\n", $raw);
        self::assertStringContainsString("Content-Length: 11\r\n", $raw);
        self::assertStringContainsString("\r\n\r\nHello World", $raw);
    }

    #[Test]
    public function it_sets_content_length_from_body(): void
    {
        $body = str_repeat('x', 42);
        $response = new Response(statusCode: ResponseStatus::OK->value, body: $body);

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("Content-Length: 42\r\n", $raw);
    }

    #[Test]
    public function it_injects_connection_close(): void
    {
        $response = new Response(statusCode: ResponseStatus::OK->value);

        $raw = $this->serializer->serialize($response, closeConnection: true, addDateHeader: false);

        self::assertStringContainsString("Connection: close\r\n", $raw);
    }

    #[Test]
    public function it_strips_transfer_encoding(): void
    {
        $response = new Response(
            statusCode: ResponseStatus::OK->value,
            headers: ['Transfer-Encoding' => 'chunked'],
            body: 'data',
        );

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringNotContainsString('Transfer-Encoding', $raw);
    }

    #[Test]
    public function it_omits_body_for_head_requests(): void
    {
        $response = new Response(statusCode: ResponseStatus::OK->value, body: 'body content');

        $raw = $this->serializer->serialize(
            $response,
            requestMethod: 'HEAD',
            addDateHeader: false,
        );

        // Should have Content-Length matching body, but no actual body
        self::assertStringContainsString("Content-Length: 12\r\n", $raw);
        self::assertStringEndsWith("\r\n\r\n", $raw);
    }

    #[Test]
    public function it_serializes_empty_body(): void
    {
        $response = new Response(statusCode: ResponseStatus::NoContent->value);

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("HTTP/1.1 204 No Content\r\n", $raw);
        self::assertStringContainsString("Content-Length: 0\r\n", $raw);
    }

    #[Test]
    public function it_adds_date_header_when_enabled(): void
    {
        $response = new Response(statusCode: ResponseStatus::OK->value);

        $raw = $this->serializer->serialize($response, addDateHeader: true);

        self::assertStringContainsString('Date:', $raw);
    }

    #[Test]
    public function it_does_not_add_date_header_when_disabled(): void
    {
        $response = new Response(statusCode: ResponseStatus::OK->value);

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringNotContainsString('Date:', $raw);
    }

    #[Test]
    public function it_preserves_existing_date_header(): void
    {
        $response = new Response(
            statusCode: ResponseStatus::OK->value,
            headers: ['Date' => 'Thu, 01 Jan 2026 00:00:00 GMT'],
        );

        $raw = $this->serializer->serialize($response, addDateHeader: true);

        self::assertStringContainsString('Date: Thu, 01 Jan 2026 00:00:00 GMT', $raw);
        // Should only appear once
        self::assertSame(1, substr_count($raw, 'Date:'));
    }

    #[Test]
    public function error_response_helper_creates_valid_http(): void
    {
        $raw = HttpResponseSerializer::errorResponse(
            500,
            'Internal Server Error',
            addDateHeader: false,
        );

        self::assertStringContainsString("HTTP/1.1 500 Internal Server Error\r\n", $raw);
        self::assertStringContainsString("Connection: close\r\n", $raw);
        self::assertStringContainsString("Content-Type: text/plain\r\n", $raw);
    }

    #[Test]
    public function it_serializes_404_response(): void
    {
        $response = new Response(statusCode: ResponseStatus::NotFound->value, body: 'Not Found');

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("HTTP/1.1 404 Not Found\r\n", $raw);
    }
}
