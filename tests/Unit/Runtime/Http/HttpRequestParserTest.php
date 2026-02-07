<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Http\ConnectionContext;
use Pulsar\Runtime\Http\HttpRequestParser;

use function sprintf;
use function strlen;

#[CoversClass(HttpRequestParser::class)]
final class HttpRequestParserTest extends TestCase
{
    private HttpRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HttpRequestParser();
    }

    private function createContext(string $data): ConnectionContext
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($socket);

        $ctx = new ConnectionContext($socket);
        $ctx->readBuffer = $data;

        return $ctx;
    }

    #[Test]
    public function it_parses_a_valid_get_request(): void
    {
        $raw = "GET /hello?name=world HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame(Method::GET, $result->method);
        self::assertSame('/hello', $result->path);
        self::assertSame('name=world', $result->queryString);
        self::assertSame('1.1', $result->protocolVersion);
        self::assertSame('localhost', $result->header('Host'));
        self::assertSame('', $result->body);
    }

    #[Test]
    public function it_parses_a_post_request_with_body(): void
    {
        $body = '{"key":"value"}';
        $raw = "POST /api/data HTTP/1.1\r\n"
             . "Host: localhost\r\n"
             . "Content-Type: application/json\r\n"
             . 'Content-Length: ' . strlen($body) . "\r\n"
             . "\r\n"
             . $body;
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame(Method::POST, $result->method);
        self::assertSame('/api/data', $result->path);
        self::assertSame($body, $result->body);
    }

    #[Test]
    public function it_returns_null_for_incomplete_headers(): void
    {
        $raw = "GET / HTTP/1.1\r\nHost: local";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_incomplete_body(): void
    {
        $raw = "POST / HTTP/1.1\r\nContent-Length: 100\r\n\r\npartial";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertNull($result);
    }

    #[Test]
    public function it_rejects_malformed_request_line(): void
    {
        $raw = "INVALID\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_rejects_unknown_method(): void
    {
        $raw = "FOOBAR /path HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_rejects_headers_too_large(): void
    {
        $header = str_repeat('X', 10000);
        $raw = "GET / HTTP/1.1\r\nBig: {$header}\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxHeaderSize: 8192);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::RequestHeaderFieldsTooLarge, $result->status);
    }

    #[Test]
    public function it_rejects_body_too_large(): void
    {
        $raw = "POST / HTTP/1.1\r\nContent-Length: 1000000\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxBodySize: 1024);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::PayloadTooLarge, $result->status);
    }

    #[Test]
    public function it_parses_cookies_from_header(): void
    {
        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc123; theme=dark\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame('abc123', $result->cookies['session']);
        self::assertSame('dark', $result->cookies['theme']);
    }

    #[Test]
    public function it_handles_keepalive_with_leftover_buffer(): void
    {
        // Two requests pipelined in one buffer
        $req1 = "GET /first HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $req2 = "GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $ctx = $this->createContext($req1 . $req2);

        $result1 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $result1);
        self::assertSame('/first', $result1->path);

        // Buffer should have the second request remaining
        $result2 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $result2);
        self::assertSame('/second', $result2->path);
    }

    #[Test]
    public function it_decodes_chunked_request_body(): void
    {
        $raw = "POST /upload HTTP/1.1\r\n"
             . "Host: localhost\r\n"
             . "Transfer-Encoding: chunked\r\n"
             . "\r\n"
             . "5\r\nHello\r\n"
             . "6\r\n World\r\n"
             . "0\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame('Hello World', $result->body);
    }

    #[Test]
    public function it_rejects_chunked_with_content_length(): void
    {
        $raw = "POST / HTTP/1.1\r\n"
             . "Transfer-Encoding: chunked\r\n"
             . "Content-Length: 10\r\n"
             . "\r\n"
             . "5\r\nHello\r\n0\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_rejects_unsupported_transfer_encoding(): void
    {
        $raw = "POST / HTTP/1.1\r\n"
             . "Transfer-Encoding: gzip\r\n"
             . "\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::NotImplemented, $result->status);
    }

    #[Test]
    public function it_rejects_chunked_body_exceeding_max_size(): void
    {
        // Chunk claims to be 2000 bytes, maxBody = 1024
        $chunkData = str_repeat('A', 2000);
        $raw = "POST / HTTP/1.1\r\n"
             . "Transfer-Encoding: chunked\r\n"
             . "\r\n"
             . sprintf("%x\r\n%s\r\n", strlen($chunkData), $chunkData)
             . "0\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxBodySize: 1024);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::PayloadTooLarge, $result->status);
    }

    #[Test]
    public function it_rejects_too_many_headers(): void
    {
        $headers = "GET / HTTP/1.1\r\n";

        for ($i = 0; $i < 110; $i++) {
            $headers .= "X-Header-{$i}: value\r\n";
        }

        $headers .= "\r\n";
        $ctx = $this->createContext($headers);

        // Use large maxHeaderSize to avoid that limit
        $result = $this->parser->parse($ctx, maxHeaderSize: 100_000);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::RequestHeaderFieldsTooLarge, $result->status);
    }

    #[Test]
    public function it_parses_http_10_request(): void
    {
        $raw = "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame('1.0', $result->protocolVersion);
    }

    #[Test]
    public function it_rejects_negative_content_length(): void
    {
        $raw = "POST / HTTP/1.1\r\nContent-Length: -1\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_handles_request_without_body_headers(): void
    {
        $raw = "DELETE /item/42 HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Request::class, $result);
        self::assertSame(Method::DELETE, $result->method);
        self::assertSame('', $result->body);
    }

    #[Test]
    public function it_rejects_incomplete_headers_exceeding_max_size(): void
    {
        // Incomplete headers (no \r\n\r\n) but already over max size
        $raw = "GET / HTTP/1.1\r\n" . str_repeat('X-Pad: value' . "\r\n", 500);
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxHeaderSize: 1024);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::RequestHeaderFieldsTooLarge, $result->status);
    }
}
