<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Security\Waf\ResponseFactoryInterface;
use Pulsar\Security\Waf\WafAction;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafMiddleware;
use Pulsar\Security\Waf\WafOperator;
use Pulsar\Security\Waf\WafRule;
use Pulsar\Security\Waf\WafSeverity;
use Pulsar\Security\Waf\WafTarget;

#[CoversClass(WafMiddleware::class)]
final class WafMiddlewareTest extends TestCase
{
    /**
     * @param array<string, string> $queryParams
     * @param array<string, string> $serverParams
     */
    private function createRequest(array $queryParams = [], array $serverParams = []): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/test');
        $uri->method('getQuery')->willReturn('');
        $uri->method('__toString')->willReturn('http://example.com/test');

        $body = $this->createStub(StreamInterface::class);
        $body->method('__toString')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getCookieParams')->willReturn([]);
        $request->method('getServerParams')->willReturn($serverParams);
        $request->method('getBody')->willReturn($body);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getHeaders')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
    }

    public function testCleanRequestPassesThrough(): void
    {
        $config = new WafConfig(enabled: true);
        $engine = new WafEngine($config);
        $engine->loadRules([
            new WafRule(
                id: 'test-1',
                message: 'Block evil',
                targets: [WafTarget::Args],
                operator: WafOperator::Contains,
                pattern: 'evil',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
            ),
        ]);

        $logger = $this->createStub(LoggerInterface::class);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);

        $middleware = new WafMiddleware($engine, $logger, $responseFactory);
        $result = $middleware->process($this->createRequest(['q' => 'hello']), $handler);

        self::assertSame($expectedResponse, $result);
    }

    public function testBlockingMatchReturns403(): void
    {
        $config = new WafConfig(enabled: true);
        $engine = new WafEngine($config);
        $engine->loadRules([
            new WafRule(
                id: 'test-1',
                message: 'Block evil',
                targets: [WafTarget::Args],
                operator: WafOperator::Contains,
                pattern: 'evil',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
            ),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $blockedResponse = $this->createStub(ResponseInterface::class);
        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($blockedResponse);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $middleware = new WafMiddleware($engine, $logger, $responseFactory);
        $result = $middleware->process($this->createRequest(['q' => 'evil payload']), $handler);

        self::assertSame($blockedResponse, $result);
    }

    public function testLogOnlyMatchPassesThrough(): void
    {
        $config = new WafConfig(enabled: true);
        $engine = new WafEngine($config);
        $engine->loadRules([
            new WafRule(
                id: 'test-log',
                message: 'Log suspicious',
                targets: [WafTarget::Args],
                operator: WafOperator::Contains,
                pattern: 'suspicious',
                action: WafAction::Log,
                severity: WafSeverity::Notice,
            ),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $middleware = new WafMiddleware($engine, $logger, $responseFactory);
        $result = $middleware->process($this->createRequest(['q' => 'suspicious activity']), $handler);

        self::assertSame($expectedResponse, $result);
    }
}
