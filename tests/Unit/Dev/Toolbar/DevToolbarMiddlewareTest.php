<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Toolbar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Dev\Toolbar\DevToolbar;
use Pulsar\Dev\Toolbar\DevToolbarMiddleware;
use Pulsar\Dev\Toolbar\ToolbarDataCollector;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(DevToolbarMiddleware::class)]
final class DevToolbarMiddlewareTest extends TestCase
{
    private function createMiddleware(bool $debugMode): DevToolbarMiddleware
    {
        return new DevToolbarMiddleware(
            debugMode: $debugMode,
            toolbar: new DevToolbar(),
            collector: new ToolbarDataCollector(),
        );
    }

    #[Test]
    public function passesResponseThroughWhenDebugDisabled(): void
    {
        $middleware = $this->createMiddleware(debugMode: false);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><body><p>Content</p></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringNotContainsString('pulsar-toolbar', $body);
    }

    #[Test]
    public function injectsToolbarIntoHtmlResponse(): void
    {
        $middleware = $this->createMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: '<html><body><p>Page content</p></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringContainsString('pulsar-toolbar', $body);
        self::assertStringContainsString('</body>', $body);
    }

    #[Test]
    public function doesNotInjectIntoJsonResponses(): void
    {
        $middleware = $this->createMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{"status":"ok"}',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/api'), $handler);

        $body = (string) $result->getBody();
        self::assertSame('{"status":"ok"}', $body);
    }

    #[Test]
    public function doesNotInjectWhenNoBodyTag(): void
    {
        $middleware = $this->createMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<p>Fragment</p>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringNotContainsString('pulsar-toolbar', $body);
    }

    #[Test]
    public function preservesOriginalStatusCode(): void
    {
        $middleware = $this->createMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 404,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><body>Not found</body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/missing'), $handler);

        self::assertSame(404, $result->getStatusCode());
    }

    #[Test]
    public function toolbarAppearsBeforeClosingBodyTag(): void
    {
        $middleware = $this->createMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><body><div>Content</div></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        $toolbarPos = strpos($body, 'pulsar-toolbar');
        $bodyClosePos = strpos($body, '</body>');

        self::assertIsInt($toolbarPos);
        self::assertIsInt($bodyClosePos);
        self::assertLessThan($bodyClosePos, $toolbarPos);
    }
}
