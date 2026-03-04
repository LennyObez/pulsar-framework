<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Analytics\Contracts\BotDetectorInterface;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Http\Message\Response;

final class BotFilterMiddlewareTest extends TestCase
{
    #[Test]
    public function bot_returns_204_no_content(): void
    {
        $detector = $this->createStub(BotDetectorInterface::class);
        $detector->method('isBot')->willReturn(true);

        $middleware = new BotFilterMiddleware($detector);
        $request = $this->createRequestStub('Googlebot/2.1');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function non_bot_proceeds_to_handler(): void
    {
        $detector = $this->createStub(BotDetectorInterface::class);
        $detector->method('isBot')->willReturn(false);

        $middleware = new BotFilterMiddleware($detector);
        $request = $this->createRequestStub('Mozilla/5.0');

        $expectedResponse = Response::json(['tracked' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function empty_user_agent_passes_through(): void
    {
        $detector = $this->createStub(BotDetectorInterface::class);
        $detector->method('isBot')->willReturn(false);

        $middleware = new BotFilterMiddleware($detector);
        $request = $this->createRequestStub('');

        $expectedResponse = Response::json([]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    private function createRequestStub(string $userAgent): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match (strtolower($name)) {
                'user-agent' => $userAgent,
                default => '',
            },
        );
        $request->method('getHeaders')->willReturn(
            $userAgent !== '' ? ['user-agent' => [$userAgent]] : [],
        );

        return $request;
    }
}
