<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\EndpointMetadata;
use Pulsar\Api\OpenApi\EndpointScanner;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\AnnotatedController;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\InvocableHandler;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\NoDocController;

#[CoversClass(EndpointScanner::class)]
final class EndpointScannerTest extends TestCase
{
    private EndpointScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new EndpointScanner();
    }

    // --- Handler resolution ---

    #[Test]
    public function scanArrayHandlerResolvesClassAndMethod(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertCount(1, $endpoints);
        self::assertSame(AnnotatedController::class, $endpoints[0]->handlerClass);
        self::assertSame('index', $endpoints[0]->handlerMethod);
    }

    #[Test]
    public function scanStringColonHandlerResolvesClassAndMethod(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertCount(1, $endpoints);
        self::assertSame(AnnotatedController::class, $endpoints[0]->handlerClass);
        self::assertSame('index', $endpoints[0]->handlerMethod);
    }

    #[Test]
    public function scanInvocableClassResolvesWithInvokeMethod(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/api/webhook',
            handler: InvocableHandler::class,
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertCount(1, $endpoints);
        self::assertSame(InvocableHandler::class, $endpoints[0]->handlerClass);
        self::assertSame('__invoke', $endpoints[0]->handlerMethod);
    }

    #[Test]
    public function scanClosureHandlerResolvesNullClassAndMethod(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/health',
            handler: static fn() => 'ok',
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertCount(1, $endpoints);
        self::assertNull($endpoints[0]->handlerClass);
        self::assertNull($endpoints[0]->handlerMethod);
    }

    #[Test]
    public function scanNonExistentStringHandlerResolvesNullClassAndMethod(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/nothing',
            handler: static fn(): string => 'noop',
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertNull($endpoints[0]->handlerClass);
        self::assertNull($endpoints[0]->handlerMethod);
    }

    // --- Path and methods ---

    #[Test]
    public function scanPreservesPathAndMethods(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/api/users/{id}',
            handler: [AnnotatedController::class, 'show'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertSame('/api/users/{id}', $endpoints[0]->path);
        self::assertSame(['GET', 'HEAD'], $endpoints[0]->methods);
    }

    // --- Middleware passthrough ---

    #[Test]
    public function scanPreservesMiddleware(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
            middleware: ['auth', 'throttle:60'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertSame(['auth', 'throttle:60'], $endpoints[0]->middleware);
    }

    // --- ApiDoc extraction ---

    #[Test]
    public function methodDocOverridesClassDoc(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);
        $doc = $endpoints[0]->doc;

        self::assertNotNull($doc);
        self::assertSame('List users', $doc->summary);
        self::assertSame('Returns paginated users', $doc->description);
        self::assertSame('listUsers', $doc->operationId);
    }

    #[Test]
    public function classDocUsedWhenMethodHasNoDoc(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users/{id}',
            handler: [AnnotatedController::class, 'show'],
        );

        $endpoints = $this->scanner->scan([$route]);
        $doc = $endpoints[0]->doc;

        self::assertNotNull($doc);
        self::assertSame('User management', $doc->summary);
    }

    #[Test]
    public function noDocWhenClassAndMethodLackAttributes(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/items',
            handler: [NoDocController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertNull($endpoints[0]->doc);
    }

    #[Test]
    public function closureHandlerHasNoDoc(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/health',
            handler: static fn() => 'ok',
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertNull($endpoints[0]->doc);
        self::assertSame([], $endpoints[0]->params);
        self::assertSame([], $endpoints[0]->responses);
    }

    // --- ApiParam extraction ---

    #[Test]
    public function extractsMultipleParams(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);
        $params = $endpoints[0]->params;

        self::assertCount(2, $params);
        self::assertSame('page', $params[0]->name);
        self::assertSame('query', $params[0]->in);
        self::assertSame('integer', $params[0]->type);
        self::assertSame('limit', $params[1]->name);
    }

    #[Test]
    public function methodWithoutParamsReturnsEmptyList(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users/{id}',
            handler: [AnnotatedController::class, 'show'],
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertSame([], $endpoints[0]->params);
    }

    // --- ApiResponse extraction ---

    #[Test]
    public function extractsMultipleResponses(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/users',
            handler: [AnnotatedController::class, 'index'],
        );

        $endpoints = $this->scanner->scan([$route]);
        $responses = $endpoints[0]->responses;

        self::assertCount(2, $responses);
        self::assertSame(200, $responses[0]->status);
        self::assertSame('Success', $responses[0]->description);
        self::assertSame(401, $responses[1]->status);
    }

    // --- Invocable method-level doc ---

    #[Test]
    public function invocableHandlerExtracts__invokeDoc(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/api/webhook',
            handler: InvocableHandler::class,
        );

        $endpoints = $this->scanner->scan([$route]);
        $doc = $endpoints[0]->doc;

        self::assertNotNull($doc);
        self::assertSame('Handle request', $doc->summary);
    }

    #[Test]
    public function invocableHandlerExtracts__invokeResponses(): void
    {
        $route = new Route(
            methods: [Method::POST],
            path: '/api/webhook',
            handler: InvocableHandler::class,
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertCount(1, $endpoints[0]->responses);
        self::assertSame(200, $endpoints[0]->responses[0]->status);
    }

    // --- Multiple routes ---

    #[Test]
    public function scanMultipleRoutesProducesMultipleEndpoints(): void
    {
        $routes = [
            new Route([Method::GET], '/api/users', [AnnotatedController::class, 'index']),
            new Route([Method::POST], '/api/webhook', InvocableHandler::class),
            new Route([Method::GET], '/health', static fn() => 'ok'),
        ];

        $endpoints = $this->scanner->scan($routes);

        self::assertCount(3, $endpoints);
        self::assertSame('/api/users', $endpoints[0]->path);
        self::assertSame('/api/webhook', $endpoints[1]->path);
        self::assertSame('/health', $endpoints[2]->path);
    }

    #[Test]
    public function scanEmptyRoutesReturnsEmptyList(): void
    {
        self::assertSame([], $this->scanner->scan([]));
    }

    // --- Non-existent class in handler ---

    #[Test]
    public function nonExistentClassInColonFormatResolvesNullDoc(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/ghost',
            handler: static fn(): mixed => null,
        );

        $endpoints = $this->scanner->scan([$route]);

        self::assertNull($endpoints[0]->doc);
        self::assertSame([], $endpoints[0]->params);
        self::assertSame([], $endpoints[0]->responses);
    }

    #[Test]
    public function classWithNonExistentMethodReturnsEmptyParamsAndResponses(): void
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/api/ghost',
            handler: [AnnotatedController::class, 'nonExistentMethod'],
        );

        $endpoints = $this->scanner->scan([$route]);

        // Class-level doc should still be found
        self::assertNotNull($endpoints[0]->doc);
        self::assertSame('User management', $endpoints[0]->doc->summary);
        // But method-level params/responses are empty
        self::assertSame([], $endpoints[0]->params);
        self::assertSame([], $endpoints[0]->responses);
    }
}
