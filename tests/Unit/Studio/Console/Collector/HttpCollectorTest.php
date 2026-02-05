<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Tracing\TraceId;
use Pulsar\Studio\Console\Collector\HttpCollector;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\HttpRequestPayload;
use Pulsar\Studio\Console\Event\Payload\HttpResponsePayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;
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

        $collector->process($request, fn(Request $r): Response => new Response('OK'));

        self::assertCount(2, $this->emittedEvents);
        self::assertInstanceOf(HttpRequestPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    public function processEmitsHttpResponseEvent(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response('OK', ResponseStatus::OK);

        $collector->process($request, fn(Request $r): Response => $response);

        self::assertCount(2, $this->emittedEvents);
        self::assertInstanceOf(HttpResponsePayload::class, $this->emittedEvents[1]['event']);
    }

    #[Test]
    public function processRecordsRequestMethod(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(Method::POST);

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('POST', $payload->method);
    }

    #[Test]
    public function processRecordsRequestUri(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(uri: '/api/users?page=1');

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('/api/users?page=1', $payload->uri);
    }

    #[Test]
    public function processRecordsRequestPath(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(uri: '/api/users?page=1', path: '/api/users');

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('/api/users', $payload->path);
    }

    #[Test]
    public function processRecordsRequestHeaders(): void
    {
        $collector = $this->createCollector();
        $headers = new HeaderBag([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
        $request = $this->createRequest(headers: $headers);

        $collector->process($request, fn(Request $r): Response => new Response());

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

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('192.168.1.100', $payload->clientIp);
    }

    #[Test]
    public function processRecordsUserAgent(): void
    {
        $collector = $this->createCollector();
        $headers = new HeaderBag(['User-Agent' => 'Mozilla/5.0']);
        $request = $this->createRequest(headers: $headers);

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('Mozilla/5.0', $payload->userAgent);
    }

    #[Test]
    public function processRecordsContentType(): void
    {
        $collector = $this->createCollector();
        $headers = new HeaderBag(['Content-Type' => 'application/json']);
        $request = $this->createRequest(headers: $headers);

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('application/json', $payload->contentType);
    }

    #[Test]
    public function processRecordsContentLength(): void
    {
        $collector = $this->createCollector();
        $headers = new HeaderBag(['Content-Length' => '1024']);
        $request = $this->createRequest(headers: $headers);

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(1024, $payload->contentLength);
    }

    #[Test]
    public function processRecordsRouteName(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_route_name' => 'api.users.index']);

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('api.users.index', $payload->routeName);
    }

    #[Test]
    public function processRecordsResponseStatusCode(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response('Created', ResponseStatus::Created);

        $collector->process($request, fn(Request $r): Response => $response);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame(201, $payload->statusCode);
    }

    #[Test]
    public function processRecordsResponseDuration(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $collector->process($request, function (Request $r): Response {
            usleep(10000); // 10ms
            return new Response();
        });

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertGreaterThan(5.0, $payload->durationMs);
    }

    #[Test]
    public function processRecordsResponseHeaders(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response('OK', headers: new HeaderBag([
            'Content-Type' => 'application/json',
            'X-Custom' => 'value',
        ]));

        $collector->process($request, fn(Request $r): Response => $response);

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
        $response = new Response('Hello, World!');

        $collector->process($request, fn(Request $r): Response => $response);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame(13, $payload->contentLength);
    }

    #[Test]
    public function processRecordsResponseContentType(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response('{}', headers: new HeaderBag(['Content-Type' => 'application/json']));

        $collector->process($request, fn(Request $r): Response => $response);

        /** @var HttpResponsePayload $payload */
        $payload = $this->emittedEvents[1]['event'];
        self::assertSame('application/json', $payload->contentType);
    }

    #[Test]
    public function processCreatesCorrelationContext(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        $collector->process($request, fn(Request $r): Response => new Response());

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

        $collector->process($request, fn(Request $r): Response => new Response());

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertSame('0123456789abcdef0123456789abcdef', $context->traceId);
    }

    #[Test]
    public function processExtractsSpanIdFromRequestAttribute(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_span_id' => 'span-123']);

        $collector->process($request, fn(Request $r): Response => new Response());

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertSame('span-123', $context->spanId);
    }

    #[Test]
    public function processExtractsIntegerSpanIdFromRequestAttribute(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_span_id' => 12345]);

        $collector->process($request, fn(Request $r): Response => new Response());

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

        $collector->process($request, function (Request $r) use (&$capturedRequest): Response {
            $capturedRequest = $r;
            return new Response();
        });

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(
            CorrelationContext::class,
            $capturedRequest->attribute('_studio_correlation'),
        );
    }

    #[Test]
    public function processReturnsResponseFromNextHandler(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $expectedResponse = new Response('Expected', ResponseStatus::Created);

        $actualResponse = $collector->process($request, fn(Request $r): Response => $expectedResponse);

        self::assertSame($expectedResponse, $actualResponse);
    }

    #[Test]
    public function processEmitsResponseEventOnException(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        try {
            $collector->process($request, function (Request $r): Response {
                throw new RuntimeException('Test exception');
            });
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

        $collector->process($request, function (Request $r): Response {
            throw new RuntimeException('Test exception');
        });
    }

    #[Test]
    public function processSkipsEmissionWhenDisabled(): void
    {
        $collector = $this->createCollector();
        $collector->enabled = false;
        $request = $this->createRequest();

        $collector->process($request, fn(Request $r): Response => new Response());

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

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->clientIp);
    }

    #[Test]
    public function processHandlesNullRouteName(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest(attributes: ['_route_name' => 123]); // Non-string

        $collector->process($request, fn(Request $r): Response => new Response());

        /** @var HttpRequestPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->routeName);
    }

    #[Test]
    public function processHandlesEmptyResponseBody(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();
        $response = new Response('');

        $collector->process($request, fn(Request $r): Response => $response);

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

        $collector->process($request, fn(Request $r): Response => new Response());

        // After processing, context should be cleaned up
        self::assertNull($this->contextProvider->current());
    }

    #[Test]
    public function processClosesContextScopeOnException(): void
    {
        $collector = $this->createCollector();
        $request = $this->createRequest();

        try {
            $collector->process($request, function (Request $r): Response {
                throw new RuntimeException('Test');
            });
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
        $response = $collector->process($request, fn(Request $r): Response => new Response('OK'));

        self::assertSame('OK', $response->body);
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

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $attributes
     */
    private function createRequest(
        Method $method = Method::GET,
        string $uri = '/test',
        string $path = '/test',
        ?HeaderBag $headers = null,
        array $server = [],
        array $attributes = [],
    ): Request {
        return new Request(
            method: $method,
            uri: $uri,
            path: $path,
            queryString: '',
            headers: $headers ?? new HeaderBag(),
            body: '',
            query: [],
            post: [],
            cookies: [],
            server: $server,
            attributes: $attributes,
        );
    }
}
