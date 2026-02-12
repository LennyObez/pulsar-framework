<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Http\Middleware\CmsApiContentNegotiationMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CmsApiContentNegotiationMiddleware::class)]
final class CmsApiContentNegotiationMiddlewareTest extends TestCase
{
    private CmsApiContentNegotiationMiddleware $middleware;
    private RequestHandlerInterface&Stub $handler;

    protected function setUp(): void
    {
        $this->middleware = new CmsApiContentNegotiationMiddleware();
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(Response::json(['ok' => true]));
    }

    #[Test]
    public function options_request_returns_204_with_cors_headers(): void
    {
        $request = new ServerRequest(method: 'OPTIONS', uri: '/api/v1/content');

        $response = $this->middleware->process($request, $this->handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization, X-API-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function regular_request_adds_cors_headers(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/api/v1/content');

        $response = $this->middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization, X-API-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function regular_request_passes_through_handler_response(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/api/v1/content');

        $response = $this->middleware->process($request, $this->handler);

        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    #[Test]
    public function post_request_gets_cors_headers(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/api/v1/content');

        $response = $this->middleware->process($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
