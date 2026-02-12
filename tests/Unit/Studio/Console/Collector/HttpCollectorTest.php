<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Studio\Console\Collector\HttpCollector;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpRequestPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\HttpResponsePayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

use function strlen;

#[CoversClass(HttpCollector::class)]
final class HttpCollectorTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    private FiberScopedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->emittedEvents = [];
        $this->contextProvider = new FiberScopedContextProvider();
    }

    #[Test]
    public function processEmitsHttpRequestEvent(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $handler = $this->createHandlerReturning(new Response(statusCode: 200, body: 'OK'));
        $collector->process($request, $handler);

        self::assertCount(2, $this->emittedEvents);
        self::assertInstanceOf(HttpRequestPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    public function processEmitsHttpResponseEvent(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: 200, body: 'OK');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        self::assertCount(2, $this->emittedEvents);
        self::assertInstanceOf(HttpResponsePayload::class, $this->emittedEvents[1]['event']);
    }

    #[Test]
    public function processRecordsRequestMethod(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(method: 'POST');

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('POST', $payload->method);
    }

    #[Test]
    public function processRecordsRequestUri(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(uri: '/api/users?page=1');

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('/api/users?page=1', $payload->uri);
    }

    #[Test]
    public function processRecordsRequestPath(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(uri: '/api/users?page=1');

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('/api/users', $payload->path);
    }

    #[Test]
    public function processRecordsRequestHeaders(): void
    {
        $collector = $this->createCollector();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $request = $this->createRequest(headers: $headers);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('application/json', $payload->headers['Content-Type']);
        self::assertSame('application/json', $payload->headers['Accept']);
    }

    #[Test]
    public function processRecordsClientIp(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(server: ['REMOTE_ADDR' => '192.168.1.100']);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('192.168.1.100', $payload->clientIp);
    }

    #[Test]
    public function processRecordsUserAgent(): void
    {
        $collector = $this->createCollector();
        $headers = ['User-Agent' => 'Mozilla/5.0'];
        $request = $this->createRequest(headers: $headers);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('Mozilla/5.0', $payload->userAgent);
    }

    #[Test]
    public function processRecordsContentType(): void
    {
        $collector = $this->createCollector();
        $headers = ['Content-Type' => 'application/json'];
        $request = $this->createRequest(headers: $headers);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('application/json', $payload->contentType);
    }

    #[Test]
    public function processRecordsContentLength(): void
    {
        $collector = $this->createCollector();
        $headers = ['Content-Length' => '1024'];
        $request = $this->createRequest(headers: $headers);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(1024, $payload->contentLength);
    }

    #[Test]
    public function processRecordsRouteName(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_route_name' => 'api.users.index']);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('api.users.index', $payload->routeName);
    }

    #[Test]
    public function processRecordsResponseStatusCode(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: ResponseStatus::Created->value, body: 'Created');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame(201, $payload->statusCode);
    }

    #[Test]
    public function processRecordsResponseDuration(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $r): ResponseInterface {
                usleep(10000); // 10ms
                return new Response();
            },
        );
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertGreaterThan(5.0, $payload->durationMs);
    }

    #[Test]
    public function processRecordsResponseHeaders(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: 200, headers: [
            'Content-Type' => 'application/json',
            'X-Custom' => 'value',
        ], body: 'OK');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame('application/json', $payload->headers['Content-Type']);
        self::assertSame('value', $payload->headers['X-Custom']);
    }

    #[Test]
    public function processRecordsResponseContentLength(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: 200, body: 'Hello, World!');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame(13, $payload->contentLength);
    }

    #[Test]
    public function processRecordsResponseContentType(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: 200, headers: ['Content-Type' => 'application/json'], body: '{}');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame('application/json', $payload->contentType);
    }

    #[Test]
    public function processCreatesCorrelationContext(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        $context = $this->emittedEvents[0]['context'];
        self::assertInstanceOf(CorrelationContext::class, $context);
        self::assertNotNull($context->requestId);
        self::assertSame(32, strlen($context->requestId)); // 16 bytes = 32 hex chars
    }

    #[Test]
    public function processExtractsTraceIdFromRequestAttribute(): void
    {
        $collector = $this->createCollector();
        // TraceId requires 32 hex characters (128-bit identifier)
        $traceId = new TraceId('0123456789abcdef0123456789abcdef');
        $request = $this->createRequest(attributes: ['_trace_context' => $traceId]);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertSame('0123456789abcdef0123456789abcdef', $context->traceId);
    }

    #[Test]
    public function processExtractsSpanIdFromRequestAttribute(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_span_id' => 'span-123']);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertSame('span-123', $context->spanId);
    }

    #[Test]
    public function processExtractsIntegerSpanIdFromRequestAttribute(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_span_id' => 12345]);

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertSame('12345', $context->spanId);
    }

    #[Test]
    public function processAddsCorrelationContextToRequest(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $capturedRequest = null;

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $r) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $r;
                return new Response();
            },
        );

        $collector->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(
            CorrelationContext::class,
            $capturedRequest->getAttribute('_studio_correlation'),
        );
    }

    #[Test]
    public function processReturnsResponseFromNextHandler(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $expectedResponse = new Response(statusCode: ResponseStatus::Created->value, body: 'Expected');

        $handler = $this->createHandlerReturning($expectedResponse);
        $actualResponse = $collector->process($request, $handler);

        self::assertSame($expectedResponse, $actualResponse);
    }

    #[Test]
    public function processEmitsResponseEventOnException(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('Test exception'));

        try {
            $collector->process($request, $handler);
        } catch (RuntimeException) {
            // Expected
        }

        self::assertCount(2, $this->emittedEvents);
        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame(500, $payload->statusCode);
    }

    #[Test]
    public function processRethrowsException(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('Test exception'));

        $collector->process($request, $handler);
    }

    #[Test]
    public function processSkipsEmissionWhenDisabled(): void
    {
        $collector = $this->createCollector();
        $collector->enabled = false;
        $request = $this->createRequest();

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $collector = $this->createCollector();

        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $collector = $this->createCollector();

        $collector->enabled = false;

        self::assertFalse($collector->enabled);

        $collector->enabled = true;

        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function processHandlesNullClientIp(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(server: ['REMOTE_ADDR' => 123]); // Non-string

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->clientIp);
    }

    #[Test]
    public function processHandlesNullRouteName(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_route_name' => 123]); // Non-string

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->routeName);
    }

    #[Test]
    public function processHandlesEmptyResponseBody(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response(statusCode: 200, body: '');

        $handler = $this->createHandlerReturning($response);
        $collector->process($request, $handler);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertNull($payload->contentLength);
    }

    #[Test]
    public function processClosesContextScopeInFinally(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        // Before processing, context should be null
        self::assertNull($this->contextProvider->current());

        $handler = $this->createHandlerReturning(new Response());
        $collector->process($request, $handler);

        // After processing, context should be cleaned up
        self::assertNull($this->contextProvider->current());
    }

    #[Test]
    public function processClosesContextScopeOnException(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('Test'));

        try {
            $collector->process($request, $handler);
        } catch (RuntimeException) {
            // Expected
        }

        // Context should be cleaned up even on exception
        self::assertNull($this->contextProvider->current());
    }

    #[Test]
    public function processSilentlySwallowsEmitExceptions(): void
    {
        $collector = new HttpCollector(
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                throw new RuntimeException('Emit failed');
            },
        );
        $request = $this->createRequest();

        // Should not throw
        $handler = $this->createHandlerReturning(new Response(statusCode: 200, body: 'OK'));
        $response = $collector->process($request, $handler);

        self::assertSame('OK', (string) $response->getBody());
    }

    private function createCollector(): HttpCollector
    {
        return new HttpCollector(
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    private function createHandlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     * @param array<string, mixed> $attributes
     */
    private function createRequest(
        string $method = 'GET',
        string $uri = '/test',
        array $headers = [],
        array $server = [],
        array $attributes = [],
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: $headers,
            serverParams: $server,
        );

        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }
}
