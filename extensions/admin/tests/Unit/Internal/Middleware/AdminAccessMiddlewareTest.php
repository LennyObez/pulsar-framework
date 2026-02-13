<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\ResponseStatus;

final class AdminAccessMiddlewareTest extends TestCase
{
    private function makeConfig(bool $enabled): AdminConfig
    {
        return AdminConfig::fromArray(['enabled' => $enabled]);
    }

    private function makeRequest(): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn(new Uri('http', 'localhost', '/admin'));

        return $request;
    }

    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    #[Test]
    public function enabled_admin_passes_through(): void
    {
        $middleware = new AdminAccessMiddleware($this->makeConfig(true));
        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($this->makeRequest(), $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function disabled_admin_returns_not_found(): void
    {
        $middleware = new AdminAccessMiddleware($this->makeConfig(false));
        $handler = $this->makeHandler(Response::json(['should' => 'not reach']));

        $response = $middleware->process($this->makeRequest(), $handler);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function disabled_admin_response_contains_error_message(): void
    {
        $middleware = new AdminAccessMiddleware($this->makeConfig(false));
        $handler = $this->makeHandler(Response::json([]));

        $response = $middleware->process($this->makeRequest(), $handler);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(['error' => 'Admin panel is disabled'], $body);
    }
}
