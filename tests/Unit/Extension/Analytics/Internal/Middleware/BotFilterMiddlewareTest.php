<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(BotFilterMiddleware::class)]
final class BotFilterMiddlewareTest extends TestCase
{
    private BotDetector $botDetector;
    private ServerRequestInterface&Stub $request;
    private RequestHandlerInterface&Stub $handler;
    private ResponseInterface $normalResponse;

    protected function setUp(): void
    {
        $this->botDetector = new BotDetector();
        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->normalResponse = Response::json(['ok' => true]);
        $this->handler->method('handle')->willReturn($this->normalResponse);
    }

    #[Test]
    public function passesThroughForHumanUserAgent(): void
    {
        $this->request->method('getHeaderLine')->willReturn('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $this->request->method('getHeaders')->willReturn([
            'User-Agent' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
            'Accept-Language' => ['en-US,en;q=0.9'],
        ]);

        $middleware = new BotFilterMiddleware($this->botDetector);
        $response = $middleware->process($this->request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }

    #[Test]
    public function returns204ForBotUserAgent(): void
    {
        $this->request->method('getHeaderLine')->willReturn('Googlebot/2.1');
        $this->request->method('getHeaders')->willReturn(['User-Agent' => ['Googlebot/2.1']]);

        $middleware = new BotFilterMiddleware($this->botDetector);
        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(204, $response->getStatusCode());
    }
}
