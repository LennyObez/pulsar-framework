<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Http\ConnectionContext;
use Pulsar\Runtime\Http\HttpRequestParser;

use function sprintf;
use function strlen;

#[CoversClass(HttpRequestParser::class)]
final class RequestLimitsTest extends TestCase
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
    public function it_rejects_oversized_headers_early(): void
    {
        // Headers that exceed the max size
        $hugeHeader = str_repeat('X', 10000);
        $raw = "GET / HTTP/1.1\r\nHuge: {$hugeHeader}\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxHeaderSize: 4096);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::RequestHeaderFieldsTooLarge, $result->status);
    }

    #[Test]
    public function it_rejects_oversized_body_with_413(): void
    {
        $raw = "POST /upload HTTP/1.1\r\nContent-Length: 1048576\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxBodySize: 1024);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::PayloadTooLarge, $result->status);
    }

    #[Test]
    public function it_returns_400_for_malformed_request_line(): void
    {
        $raw = "NOT_A_VALID_REQUEST\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_returns_400_for_missing_http_version(): void
    {
        $raw = "GET /path\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }

    #[Test]
    public function it_rejects_request_line_exceeding_header_limit(): void
    {
        $longPath = '/' . str_repeat('a', 9000);
        $raw = "GET {$longPath} HTTP/1.1\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxHeaderSize: 8192);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::RequestHeaderFieldsTooLarge, $result->status);
    }

    #[Test]
    public function it_rejects_chunked_body_exceeding_max_size(): void
    {
        $chunkData = str_repeat('B', 5000);
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
    public function it_accepts_request_within_all_limits(): void
    {
        $body = 'small body';
        $raw = "POST /ok HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx, maxHeaderSize: 8192, maxBodySize: 1024);

        self::assertInstanceOf(\Pulsar\Http\Request::class, $result);
        self::assertSame('small body', $result->body);
    }

    #[Test]
    public function it_rejects_header_without_colon(): void
    {
        $raw = "GET / HTTP/1.1\r\nBadHeader\r\n\r\n";
        $ctx = $this->createContext($raw);

        $result = $this->parser->parse($ctx);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(ResponseStatus::BadRequest, $result->status);
    }
}
