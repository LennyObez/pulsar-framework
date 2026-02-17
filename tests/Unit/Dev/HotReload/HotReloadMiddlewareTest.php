<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\HotReload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Dev\HotReload\HotReloadMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(HotReloadMiddleware::class)]
final class HotReloadMiddlewareTest extends TestCase
{
    #[Test]
    public function passesResponseThroughWhenDebugModeDisabled(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: false);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><body><p>Hello</p></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringNotContainsString('data-pulsar-hot-reload', $body);
        self::assertFalse($result->hasHeader('X-Pulsar-HotReload'));
    }

    #[Test]
    public function injectsScriptIntoHtmlResponseInDebugMode(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true, wsPort: 9090);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: '<html><body><p>Content</p></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringContainsString('data-pulsar-hot-reload', $body);
        self::assertStringContainsString(':9090', $body);
        self::assertStringContainsString('</body>', $body);
        self::assertSame('active', $result->getHeaderLine('X-Pulsar-HotReload'));
    }

    #[Test]
    public function doesNotInjectIntoNonHtmlResponses(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{"ok":true}',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/api'), $handler);

        $body = (string) $result->getBody();
        self::assertStringNotContainsString('data-pulsar-hot-reload', $body);
        self::assertSame('{"ok":true}', $body);
    }

    #[Test]
    public function doesNotInjectWhenNoBodyTag(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><p>Fragment without body tag</p></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        $body = (string) $result->getBody();
        self::assertStringNotContainsString('data-pulsar-hot-reload', $body);
    }

    #[Test]
    public function buildScriptUsesSecureProtocolDetection(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true, wsPort: 8081);
        $script = $middleware->buildScript();

        // Should use dynamic protocol detection, not hardcoded ws://
        self::assertStringContainsString('wsProto', $script);
        self::assertStringContainsString("'wss:'", $script);
        self::assertStringContainsString("'ws:'", $script);
    }

    #[Test]
    public function buildScriptContainsReconnectionLogic(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);
        $script = $middleware->buildScript();

        self::assertStringContainsString('reconnectDelay', $script);
        self::assertStringContainsString('maxReconnectDelay', $script);
        self::assertStringContainsString('setTimeout', $script);
    }

    #[Test]
    public function buildScriptContainsCssHotSwap(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);
        $script = $middleware->buildScript();

        self::assertStringContainsString('reloadStylesheets', $script);
        self::assertStringContainsString('_hmr', $script);
    }

    #[Test]
    public function usesDefaultPortWhenNotSpecified(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);
        $script = $middleware->buildScript();

        self::assertStringContainsString(':8081', $script);
    }

    #[Test]
    public function preservesOriginalResponseStatusCode(): void
    {
        $middleware = new HotReloadMiddleware(debugMode: true);

        $response = new Response(
            statusCode: 201,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><body></body></html>',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);

        self::assertSame(201, $result->getStatusCode());
    }
}
