<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Http\ConnectionContext;
use Pulsar\Runtime\Http\HttpRequestParser;
use Pulsar\Runtime\Http\HttpResponseSerializer;

#[CoversClass(HttpRequestParser::class)]
#[CoversClass(HttpResponseSerializer::class)]
final class KeepAliveTest extends TestCase
{
    private HttpRequestParser $parser;
    private HttpResponseSerializer $serializer;

    protected function setUp(): void
    {
        $this->parser = new HttpRequestParser();
        $this->serializer = new HttpResponseSerializer();
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
    public function it_handles_multiple_requests_on_same_connection(): void
    {
        // Simulate two pipelined requests in a single buffer
        $req1 = "GET /first HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";
        $req2 = "GET /second HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";

        $ctx = $this->createContext($req1 . $req2);

        // Parse first request
        $request1 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $request1);
        self::assertSame('/first', $request1->path);

        // Verify connection header
        self::assertSame('keep-alive', $request1->header('Connection'));

        // Parse second request from remaining buffer
        $request2 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $request2);
        self::assertSame('/second', $request2->path);

        // Buffer should now be empty
        self::assertSame('', $ctx->readBuffer);
    }

    #[Test]
    public function it_isolates_state_between_pipelined_requests(): void
    {
        $req1 = "POST /api/data HTTP/1.1\r\nHost: localhost\r\nContent-Length: 11\r\n\r\nHello World";
        $req2 = "GET /status HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $ctx = $this->createContext($req1 . $req2);

        $request1 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $request1);
        self::assertSame(Method::POST, $request1->method);
        self::assertSame('Hello World', $request1->body);

        $request2 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $request2);
        self::assertSame(Method::GET, $request2->method);
        self::assertSame('', $request2->body);
    }

    #[Test]
    public function it_handles_partial_read_across_boundary(): void
    {
        // First read: partial headers
        $ctx = $this->createContext("GET / HTTP/1.1\r\nHost: loc");

        $result = $this->parser->parse($ctx);
        self::assertNull($result); // Incomplete

        // Second read: rest of headers
        $ctx->appendToBuffer("alhost\r\n\r\n");

        $result = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $result);
        self::assertSame('/', $result->path);
    }

    #[Test]
    public function it_handles_partial_body_read(): void
    {
        // First read: headers + partial body
        $ctx = $this->createContext("POST / HTTP/1.1\r\nContent-Length: 10\r\n\r\nHello");

        $result = $this->parser->parse($ctx);
        self::assertNull($result); // Incomplete body

        // Second read: rest of body
        $ctx->appendToBuffer('World');

        $result = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $result);
        self::assertSame('HelloWorld', $result->body);
    }

    #[Test]
    public function response_always_has_content_length_for_keepalive(): void
    {
        $response = new Response(
            body: 'Hello',
            status: ResponseStatus::OK,
        );

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("Content-Length: 5\r\n", $raw);
    }

    #[Test]
    public function response_injects_connection_close_when_closing(): void
    {
        $response = new Response(body: 'error', status: ResponseStatus::InternalServerError);

        $raw = $this->serializer->serialize(
            $response,
            closeConnection: true,
            addDateHeader: false,
        );

        self::assertStringContainsString("Connection: close\r\n", $raw);
    }

    #[Test]
    public function it_handles_http_10_keepalive(): void
    {
        // HTTP/1.0 with explicit Connection: keep-alive
        $raw = "GET / HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";
        $ctx = $this->createContext($raw);

        $request = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('1.0', $request->protocolVersion);
        self::assertSame('keep-alive', $request->header('Connection'));
    }

    #[Test]
    public function it_handles_three_pipelined_requests(): void
    {
        $req1 = "GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $req2 = "GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $req3 = "GET /c HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $ctx = $this->createContext($req1 . $req2 . $req3);

        $r1 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $r1);
        self::assertSame('/a', $r1->path);

        $r2 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $r2);
        self::assertSame('/b', $r2->path);

        $r3 = $this->parser->parse($ctx);
        self::assertInstanceOf(Request::class, $r3);
        self::assertSame('/c', $r3->path);
    }
}
