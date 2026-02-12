<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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
        self::assertInstanceOf(ServerRequest::class, $request1);
        self::assertSame('/first', $request1->getUri()->getPath());

        // Verify connection header
        self::assertSame('keep-alive', $request1->getHeaderLine('Connection'));

        // Parse second request from remaining buffer
        $request2 = $this->parser->parse($ctx);
        self::assertInstanceOf(ServerRequest::class, $request2);
        self::assertSame('/second', $request2->getUri()->getPath());

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
        self::assertInstanceOf(ServerRequest::class, $request1);
        self::assertSame('POST', $request1->getMethod());
        self::assertSame('Hello World', (string) $request1->getBody());

        $request2 = $this->parser->parse($ctx);
        self::assertInstanceOf(ServerRequest::class, $request2);
        self::assertSame('GET', $request2->getMethod());
        self::assertSame('', (string) $request2->getBody());
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
        self::assertInstanceOf(ServerRequest::class, $result);
        self::assertSame('/', $result->getUri()->getPath());
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
        self::assertInstanceOf(ServerRequest::class, $result);
        self::assertSame('HelloWorld', (string) $result->getBody());
    }

    #[Test]
    public function response_always_has_content_length_for_keepalive(): void
    {
        $response = new Response(
            statusCode: ResponseStatus::OK->value,
            body: 'Hello',
        );

        $raw = $this->serializer->serialize($response, addDateHeader: false);

        self::assertStringContainsString("Content-Length: 5\r\n", $raw);
    }

    #[Test]
    public function response_injects_connection_close_when_closing(): void
    {
        $response = new Response(statusCode: ResponseStatus::InternalServerError->value, body: 'error');

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
        self::assertInstanceOf(ServerRequest::class, $request);
        self::assertSame('1.0', $request->getProtocolVersion());
        self::assertSame('keep-alive', $request->getHeaderLine('Connection'));
    }

    #[Test]
    public function it_handles_three_pipelined_requests(): void
    {
        $req1 = "GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $req2 = "GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $req3 = "GET /c HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $ctx = $this->createContext($req1 . $req2 . $req3);

        $r1 = $this->parser->parse($ctx);
        self::assertInstanceOf(ServerRequest::class, $r1);
        self::assertSame('/a', $r1->getUri()->getPath());

        $r2 = $this->parser->parse($ctx);
        self::assertInstanceOf(ServerRequest::class, $r2);
        self::assertSame('/b', $r2->getUri()->getPath());

        $r3 = $this->parser->parse($ctx);
        self::assertInstanceOf(ServerRequest::class, $r3);
        self::assertSame('/c', $r3->getUri()->getPath());
    }
}
