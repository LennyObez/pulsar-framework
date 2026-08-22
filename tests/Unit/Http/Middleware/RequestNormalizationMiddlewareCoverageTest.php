<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\RequestNormalizationMiddleware;

/**
 * Additional coverage for RequestNormalizationMiddleware:
 * null byte in query string and decoded traversal edge cases.
 */
#[CoversClass(RequestNormalizationMiddleware::class)]
final class RequestNormalizationMiddlewareCoverageTest extends TestCase
{
    private RequestNormalizationMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RequestNormalizationMiddleware();
    }

    #[Test]
    public function rejectsNullByteInQueryString(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/search?q=test%00inject');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function allowsEmptyQueryString(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/page');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allowsNormalQueryString(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/search?q=hello&page=2');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsDoubleEncodedTraversal(): void
    {
        // %252e%252e -> decoded once: %2e%2e -> decoded again: ..
        $request = new ServerRequest(method: 'GET', uri: '/%252e%252e/etc/passwd');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function allowsSingleDotInPath(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/./current');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allowsDotsInFilenames(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/files/report.2024.pdf');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
