<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Collector;

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
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

#[CoversClass(HttpCollector::class)]
final class HttpCollectorTest extends TestCase
{
    private FiberScopedContextProvider $contextProvider;
    private Randomizer $randomizer;

    protected function setUp(): void
    {
        $this->contextProvider = new FiberScopedContextProvider();
        $this->randomizer = new Randomizer(new Mt19937(12345));
    }

    /**
     * @param array<string, list<string>|string> $headers
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $attributes
     */
    private function makeRequest(
        string $method = 'GET',
        string $uri = '/test',
        string $body = '',
        array $headers = [],
        array $serverParams = [],
        array $attributes = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $uri,
            headers: $headers,
            body: $body,
            serverParams: $serverParams,
            attributes: $attributes,
        );
    }

    private function makeHandler(Response $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function makeThrowingHandler(Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $exception) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }

    #[Test]
    public function processEmitsRequestAndResponseEvents(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest(
            method: 'POST',
            uri: '/api/users?page=1',
            body: '{"name":"Alice"}',
            headers: [
                'Content-Type' => 'application/json',
                'Content-Length' => '16',
                'User-Agent' => 'TestAgent/1.0',
            ],
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
            attributes: ['_route_name' => 'api.users.create'],
        );

        $expectedResponse = new Response(
            body: '{"id":1}',
            statusCode: ResponseStatus::Created->value,
            headers: ['Content-Type' => 'application/json'],
        );

        $response = $collector->process($request, $this->makeHandler($expectedResponse));

        self::assertSame($expectedResponse, $response);
        self::assertCount(2, $events);
        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);

        $reqPayload = $events[0];
        self::assertSame('POST', $reqPayload->method);
        self::assertSame('/api/users?page=1', $reqPayload->uri);
        self::assertSame('/api/users', $reqPayload->path);
        self::assertSame('192.168.1.1', $reqPayload->clientIp);
        self::assertSame('TestAgent/1.0', $reqPayload->userAgent);
        self::assertSame('application/json', $reqPayload->contentType);
        self::assertSame(16, $reqPayload->contentLength);
        self::assertSame('api.users.create', $reqPayload->routeName);
        self::assertSame('page=1', $reqPayload->queryString);
        self::assertSame('{"name":"Alice"}', $reqPayload->bodyPreview);

        $respPayload = $events[1];
        self::assertSame(201, $respPayload->statusCode);
        self::assertGreaterThan(0.0, $respPayload->durationMs);
        self::assertSame('api.users.create', $respPayload->routeName);
    }

    #[Test]
    public function processSkipsEventsWhenDisabled(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);
        $collector->enabled = false;

        $request = $this->makeRequest();
        $expectedResponse = new Response();

        $response = $collector->process($request, $this->makeHandler($expectedResponse));

        self::assertSame($expectedResponse, $response);
        self::assertCount(0, $events);
    }

    #[Test]
    public function processEmitsResponseWithStatus500OnException(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $exception = new RuntimeException('Something went wrong');

        try {
            $collector->process($request, $this->makeThrowingHandler($exception));
            self::fail('Expected exception to be rethrown');
        } catch (RuntimeException $e) {
            self::assertSame($exception, $e);
        }

        self::assertCount(2, $events);
        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);

        $respPayload = $events[1];
        self::assertSame(500, $respPayload->statusCode);
        self::assertGreaterThan(0.0, $respPayload->durationMs);
    }

    #[Test]
    public function processExtractsTraceIdFromAttribute(): void
    {
        $capturedContexts = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$capturedContexts): void {
            $capturedContexts[] = $ctx;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $traceId = new TraceId('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6');
        $request = $this->makeRequest(attributes: [
            '_trace_context' => $traceId,
            '_span_id' => 'span-abc-123',
        ]);

        $collector->process($request, $this->makeHandler(new Response()));

        self::assertNotEmpty($capturedContexts);
        self::assertNotNull($capturedContexts[0]);
        self::assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $capturedContexts[0]->traceId);
        self::assertSame('span-abc-123', $capturedContexts[0]->spanId);
    }

    #[Test]
    public function processHandlesIntSpanId(): void
    {
        $capturedContexts = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$capturedContexts): void {
            $capturedContexts[] = $ctx;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest(attributes: [
            '_span_id' => 42,
        ]);

        $collector->process($request, $this->makeHandler(new Response()));

        self::assertNotEmpty($capturedContexts);
        self::assertNotNull($capturedContexts[0]);
        self::assertSame('42', $capturedContexts[0]->spanId);
    }

    #[Test]
    public function processHandlesNullSpanId(): void
    {
        $capturedContexts = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$capturedContexts): void {
            $capturedContexts[] = $ctx;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();

        $collector->process($request, $this->makeHandler(new Response()));

        self::assertNotEmpty($capturedContexts);
        self::assertNotNull($capturedContexts[0]);
        self::assertNull($capturedContexts[0]->spanId);
    }

    #[Test]
    public function processHandlesResponseWithTextualBodyPreview(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $response = new Response(
            body: '{"data":"value"}',
            statusCode: ResponseStatus::OK->value,
            headers: ['Content-Type' => 'application/json'],
        );

        $collector->process($request, $this->makeHandler($response));

        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);
        self::assertSame('{"data":"value"}', $events[1]->bodyPreview);
    }

    #[Test]
    public function processOmitsBodyPreviewForBinaryContentType(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $response = new Response(
            body: "\x00\x01\x02\x03",
            statusCode: ResponseStatus::OK->value,
            headers: ['Content-Type' => 'application/octet-stream'],
        );

        $collector->process($request, $this->makeHandler($response));

        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);
        self::assertNull($events[1]->bodyPreview);
    }

    #[Test]
    public function processHandlesEmptyBody(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest(body: '');
        $collector->process($request, $this->makeHandler(new Response()));

        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertNull($events[0]->bodyPreview);
    }

    #[Test]
    public function processHandlesNullRemoteAddr(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $collector->process($request, $this->makeHandler(new Response()));

        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertNull($events[0]->clientIp);
    }

    #[Test]
    public function processHandlesEmptyQueryString(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $collector->process($request, $this->makeHandler(new Response()));

        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertNull($events[0]->queryString);
    }

    #[Test]
    public function processSwallowsEmitException(): void
    {
        $callCount = 0;
        $emit = function () use (&$callCount): never {
            $callCount++;
            throw new RuntimeException('emit failed');
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $response = $collector->process($request, $this->makeHandler(new Response()));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function processHandlesXmlContentType(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $response = new Response(
            body: '<root>test</root>',
            statusCode: ResponseStatus::OK->value,
            headers: ['Content-Type' => 'application/xml'],
        );

        $collector->process($request, $this->makeHandler($response));

        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);
        self::assertSame('<root>test</root>', $events[1]->bodyPreview);
    }

    #[Test]
    public function processHandlesTextPlainContentType(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest();
        $response = new Response(
            body: 'Hello world',
            statusCode: ResponseStatus::OK->value,
            headers: ['Content-Type' => 'text/plain'],
        );

        $collector->process($request, $this->makeHandler($response));

        self::assertInstanceOf(HttpResponsePayload::class, $events[1]);
        self::assertSame('Hello world', $events[1]->bodyPreview);
    }

    #[Test]
    public function processHandlesNonStringTraceContext(): void
    {
        $capturedContexts = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$capturedContexts): void {
            $capturedContexts[] = $ctx;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest(attributes: [
            '_trace_context' => 'not-a-TraceId-object',
        ]);

        $collector->process($request, $this->makeHandler(new Response()));

        self::assertNotNull($capturedContexts[0]);
        self::assertNull($capturedContexts[0]->traceId);
    }

    #[Test]
    public function processHandlesNonStringRouteName(): void
    {
        $events = [];
        $emit = function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$events): void {
            $events[] = $event;
        };

        $collector = new HttpCollector($this->contextProvider, $emit, $this->randomizer);

        $request = $this->makeRequest(attributes: ['_route_name' => 42]);
        $collector->process($request, $this->makeHandler(new Response()));

        self::assertInstanceOf(HttpRequestPayload::class, $events[0]);
        self::assertNull($events[0]->routeName);
    }
}
